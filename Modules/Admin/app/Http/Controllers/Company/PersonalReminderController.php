<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\PersonalReminder;

/**
 * Personal reminders for head + accountant (COMPANY_DASHBOARD_API_SPEC.md
 * §5.2 head-reminders / §5.3 accountant-reminders). One model, role-aware shape.
 */
class PersonalReminderController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = PersonalReminder::where('user_id', $request->user()->id);
            if ($request->has('done')) {
                $q->where('done', filter_var($request->query('done'), FILTER_VALIDATE_BOOLEAN));
            }
            $role = $this->roleFromPath($request);

            return $this->listResponse($q->orderByDesc('due_at')->get()->map(fn ($r) => $this->present($r, $role))->all());
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $role = $this->roleFromPath($request);
            $data = $request->validate([
                'title' => 'required|string|max:200', 'description' => 'sometimes|nullable|string', 'body' => 'sometimes|nullable|string',
                'dueAt' => 'sometimes|nullable|date', 'priority' => 'sometimes|in:high,medium,low', 'type' => 'sometimes|in:urgent,report,finance,team',
            ]);
            $r = PersonalReminder::create([
                'company_id' => $request->user()->company_id, 'user_id' => $request->user()->id, 'role_key' => $role,
                'title' => $data['title'], 'body' => $data['body'] ?? $data['description'] ?? null,
                'type' => $data['type'] ?? 'finance', 'priority' => $data['priority'] ?? 'medium',
                'due_at' => $data['dueAt'] ?? null, 'done' => false,
            ]);

            return $this->created($this->present($r, $role));
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $r = PersonalReminder::where('user_id', $request->user()->id)->findOrFail($id);
            $data = $request->validate([
                'done' => 'sometimes|boolean', 'title' => 'sometimes|string|max:200',
                'description' => 'sometimes|nullable|string', 'body' => 'sometimes|nullable|string',
                'dueAt' => 'sometimes|nullable|date', 'priority' => 'sometimes|in:high,medium,low',
            ]);
            $r->update(array_filter([
                'done' => $data['done'] ?? null, 'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? $data['description'] ?? null, 'due_at' => $data['dueAt'] ?? null,
                'priority' => $data['priority'] ?? null,
            ], fn ($v) => $v !== null));

            return $this->ok($this->present($r->fresh(), $this->roleFromPath($request)));
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            PersonalReminder::where('user_id', $request->user()->id)->findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    public function markAllDone(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            PersonalReminder::where('user_id', $request->user()->id)->where('done', false)->update(['done' => true]);

            return $this->noContent();
        });
    }

    private function roleFromPath(Request $request): string
    {
        return str_contains($request->path(), '/head/') ? 'head' : 'accountant';
    }

    private function present(PersonalReminder $r, string $role): array
    {
        if ($role === 'head') {
            return [
                'id' => $r->id, 'titleAr' => $r->title, 'titleEn' => $r->title, 'bodyAr' => $r->body, 'bodyEn' => $r->body,
                'type' => $r->type, 'icon' => ['urgent' => '🔴', 'report' => '📊', 'finance' => '💰', 'team' => '👥'][$r->type] ?? '🔔',
                'timeAr' => optional($r->due_at)->diffForHumans(), 'createdAt' => optional($r->created_at)->toIso8601String(), 'done' => (bool) $r->done,
            ];
        }

        return [
            'id' => $r->id, 'title' => $r->title, 'description' => $r->body,
            'dueAt' => optional($r->due_at)->toIso8601String(), 'dueAtLabel' => optional($r->due_at)->diffForHumans(),
            'priority' => $r->priority, 'done' => (bool) $r->done,
        ];
    }
}
