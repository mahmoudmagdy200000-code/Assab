<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Services\ReportService;

/**
 * Company-scoped cross-cutting endpoints (COMPANY_DASHBOARD_API_SPEC.md §7):
 * lookups, audit log, notification delete, report download, user preferences.
 */
class CrossController extends AsabController
{
    public function cities(): JsonResponse
    {
        return $this->listResponse(['الرياض', 'جدة', 'الدمام', 'مكة المكرمة', 'المدينة المنورة', 'الخبر', 'تبوك', 'بريدة', 'أبها', 'الطائف', 'الأحساء', 'حائل']);
    }

    public function units(): JsonResponse
    {
        return $this->listResponse(['كجم', 'لتر', 'قطعة', 'كرتون', 'كيس', 'عبوة', 'شريحة']);
    }

    public function assetCategories(): JsonResponse
    {
        return $this->listResponse([
            ['id' => 'kitchen', 'name' => 'معدات مطبخ', 'depreciationRate' => 20],
            ['id' => 'furniture', 'name' => 'أثاث', 'depreciationRate' => 10],
            ['id' => 'electronics', 'name' => 'أجهزة إلكترونية', 'depreciationRate' => 25],
            ['id' => 'vehicles', 'name' => 'مركبات', 'depreciationRate' => 20],
            ['id' => 'other', 'name' => 'أخرى', 'depreciationRate' => 15],
        ]);
    }

    public function inventoryCategories(Request $request): JsonResponse
    {
        $brandIds = AsabBrand::where('company_id', $request->user()->company_id)->pluck('id');
        $cats = InventoryCatalogItem::whereIn('brand_id', $brandIds)->whereNotNull('category')->distinct()->pluck('category')->filter()->values()->all();

        return $this->listResponse($cats ?: ['المشروبات', 'الوجبات', 'المواد الخام', 'مواد التغليف']);
    }

    public function expenseCategories(): JsonResponse
    {
        return $this->listResponse([
            ['id' => 'rent', 'name' => 'إيجار', 'isActive' => true],
            ['id' => 'utilities', 'name' => 'خدمات (كهرباء/ماء)', 'isActive' => true],
            ['id' => 'salaries', 'name' => 'رواتب', 'isActive' => true],
            ['id' => 'marketing', 'name' => 'تسويق', 'isActive' => true],
            ['id' => 'maintenance', 'name' => 'صيانة', 'isActive' => true],
            ['id' => 'other', 'name' => 'أخرى', 'isActive' => true],
        ]);
    }

    public function supplierCategories(): JsonResponse
    {
        return $this->listResponse([
            ['id' => 'food', 'name' => 'مواد غذائية', 'isActive' => true],
            ['id' => 'beverages', 'name' => 'مشروبات', 'isActive' => true],
            ['id' => 'packaging', 'name' => 'تغليف', 'isActive' => true],
            ['id' => 'equipment', 'name' => 'معدات', 'isActive' => true],
            ['id' => 'services', 'name' => 'خدمات', 'isActive' => true],
        ]);
    }

    public function notificationDestroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            AsabNotification::where('user_id', $request->user()->id)->where('id', $id)->delete();

            return $this->noContent();
        });
    }

    public function auditLogs(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = AuditLog::where('company_id', $request->user()->company_id);
            if ($a = $request->query('action')) {
                $q->where('action', 'like', "%{$a}%");
            }
            if ($e = $request->query('entityType')) {
                $q->where('entity_type', $e);
            }
            if ($u = $request->query('actorUserId')) {
                $q->where('actor_user_id', $u);
            }
            if ($df = $request->query('dateFrom')) {
                $q->where('occurred_at', '>=', $df);
            }
            if ($dt = $request->query('dateTo')) {
                $q->where('occurred_at', '<=', $dt.' 23:59:59');
            }
            $page = $q->orderByDesc('occurred_at')->paginate(min((int) $request->query('pageSize', 20), 100));

            $items = collect($page->items())->map(fn (AuditLog $l) => [
                'id' => $l->id, 'action' => $l->action, 'entityType' => $l->entity_type, 'entityId' => $l->entity_id,
                'actor' => ['id' => $l->actor_user_id, 'name' => $l->actor_label], 'description' => $l->description,
                'ip' => $l->ip, 'timestamp' => optional($l->occurred_at)->toIso8601String(),
            ])->all();

            return $this->paginated($page, $items);
        });
    }

    public function reportDownload(Request $request, ReportService $reports, \Modules\Admin\Services\ExportService $exports, string $key)
    {
        $format = $request->query('format', 'json');
        $report = $reports->build([
            'reportKey' => $key,
            'period' => ['from' => $request->query('period') ?? $request->query('from'), 'to' => $request->query('to')],
            'branchIds' => (array) ($request->query('branchIds') ?? array_filter([$request->query('branchId')])),
        ]);

        // pdf|xlsx → real binary stream; json → the raw payload (spec §7.4).
        if (in_array($format, ['pdf', 'xlsx'], true)) {
            return $exports->report($report, $format);
        }

        return $this->run(fn () => $this->ok($report));
    }

    public function userPreferences(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['language' => 'sometimes|in:ar,en', 'theme' => 'sometimes|in:light,dark']);

            // UI preferences are persisted client-side; echo back for optional server sync.
            return $this->ok(['language' => $data['language'] ?? 'ar', 'theme' => $data['theme'] ?? 'light']);
        });
    }
}
