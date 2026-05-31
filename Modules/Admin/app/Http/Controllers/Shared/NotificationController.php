<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabNotification;

/**
 * Per-user notifications (BACKEND_API_SPEC.md §7.1).
 */
class NotificationController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $q = AsabNotification::where('user_id', $request->user()->id);
            if ($request->boolean('unreadOnly')) {
                $q->whereNull('read_at');
            }
            if ($type = $request->query('type')) {
                $q->where('type', $type);
            }

            $unread = AsabNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count();
            $p = $q->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'link' => $n->link,
                'refType' => $n->ref_type,
                'refId' => $n->ref_id,
                'readAt' => optional($n->read_at)->toIso8601String(),
                'createdAt' => optional($n->created_at)->toIso8601String(),
            ], $p->items()), ['unreadCount' => $unread]);
        });
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            AsabNotification::where('user_id', $request->user()->id)->where('id', $id)->update(['read_at' => now()]);

            return $this->noContent();
        });
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            AsabNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

            return $this->noContent();
        });
    }
}
