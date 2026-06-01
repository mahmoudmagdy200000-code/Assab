<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AuditLog;

class AuditLogController extends AsabController
{
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
}
