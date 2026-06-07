<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Services\ExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditLogController extends AsabController
{
    public function __construct(private readonly ExportService $exports) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = AuditLog::query();

            if ($actor = $request->query('actorUserId')) {
                $q->where('actor_user_id', $actor);
            }
            if ($action = $request->query('action')) {
                $q->where('action', $action);
            }
            if ($entity = $request->query('entityType')) {
                $q->where('entity_type', $entity);
            }
            if ($from = $request->query('dateFrom')) {
                $q->where('occurred_at', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->where('occurred_at', '<=', $to);
            }
            if ($search = $request->query('search')) {
                $q->where('description', 'like', "%{$search}%");
            }

            $p = $q->orderByDesc('occurred_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actorName' => $l->actor_label,
                'actorRole' => $l->actor_role,
                'entityType' => $l->entity_type,
                'entityId' => $l->entity_id,
                'description' => $l->description,
                'ip' => $l->ip,
                'occurredAt' => optional($l->occurred_at)->toIso8601String(),
                'before' => $l->before,
                'after' => $l->after,
            ], $p->items()));
        });
    }

    /** GET /admin/audit-logs/export?format=xlsx&userFilter=&actionType=&dateFrom=&dateTo= */
    public function export(Request $request): BinaryFileResponse
    {
        $format = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        return $this->exports->auditLogs($format, [
            'action' => $request->query('actionType', $request->query('action')),
            'actorUserId' => $request->query('actorUserId'),
            'userFilter' => $request->query('userFilter'),
            'dateFrom' => $request->query('dateFrom'),
            'dateTo' => $request->query('dateTo'),
        ]);
    }

    /** GET /admin/audit-logs/filters — action-type dropdown metadata. */
    public function filters(): JsonResponse
    {
        return $this->run(fn () => $this->ok(['actionTypes' => self::actionTypes()]));
    }

    /** Canonical audit action-type vocabulary (shared by UI filter + exports). */
    public static function actionTypes(): array
    {
        return [
            ['value' => 'users', 'labelAr' => 'مستخدمين', 'labelEn' => 'Users'],
            ['value' => 'approvals', 'labelAr' => 'اعتمادات', 'labelEn' => 'Approvals'],
            ['value' => 'subscriptions', 'labelAr' => 'اشتراكات', 'labelEn' => 'Subscriptions'],
            ['value' => 'rejection', 'labelAr' => 'رفض', 'labelEn' => 'Rejection'],
            ['value' => 'export', 'labelAr' => 'تصدير', 'labelEn' => 'Export'],
            ['value' => 'inventory', 'labelAr' => 'مخزون', 'labelEn' => 'Inventory'],
            ['value' => 'permissions', 'labelAr' => 'صلاحيات', 'labelEn' => 'Permissions'],
            ['value' => 'purchases', 'labelAr' => 'مشتريات', 'labelEn' => 'Purchases'],
        ];
    }
}
