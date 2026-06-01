<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AutoReminderRule;
use Modules\Admin\Models\Reminder;

/**
 * Reminders + auto-reminder rules (BACKEND_API_SPEC.md §6.3.12).
 */
class ReminderController extends AsabController
{
    public function send(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $r = Reminder::findOrFail($id);
            $r->update(['reminder_status' => 'sent', 'sent_at' => now()]);

            return $this->ok(['id' => $r->id, 'reminderStatus' => $r->reminder_status]);
        });
    }

    public function bulkSend(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $ids = $request->input('ids');
            $q = Reminder::where('reminder_status', 'not_sent');
            if (is_array($ids) && $ids) {
                $q->whereIn('id', $ids);
            }
            $count = $q->update(['reminder_status' => 'sent', 'sent_at' => now()]);

            return $this->ok(['sent' => $count]);
        });
    }

    public function respond(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $id) {
            $data = $request->validate(['response' => 'required|string|max:80']);
            $r = Reminder::findOrFail($id);
            $r->update(['reminder_status' => 'responded', 'response' => $data['response'], 'responded_at' => now()]);
            $rt->reminderResponded($r->fresh());

            return $this->ok(['id' => $r->id, 'reminderStatus' => $r->reminder_status, 'response' => $r->response]);
        });
    }

    public function rules(): JsonResponse
    {
        return $this->run(fn () => $this->listResponse(
            AutoReminderRule::orderBy('trigger_hour')->get()->map(fn ($r) => [
                'id' => $r->id, 'module' => $r->module, 'triggerHour' => $r->trigger_hour,
                'repeatHours' => $r->repeat_hours, 'active' => (bool) $r->active,
            ])->all()
        ));
    }

    public function storeRule(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'module' => 'required|string|max:32',
                'triggerHour' => 'required|string|max:8',
                'repeatHours' => 'required|integer|min:1',
                'active' => 'sometimes|boolean',
            ]);
            $rule = AutoReminderRule::create([
                'company_id' => $request->user()->company_id,
                'module' => $data['module'],
                'trigger_hour' => $data['triggerHour'],
                'repeat_hours' => $data['repeatHours'],
                'active' => $data['active'] ?? true,
            ]);

            return $this->created(['id' => $rule->id, 'module' => $rule->module]);
        });
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $rule = AutoReminderRule::findOrFail($id);
            $data = $request->validate([
                'triggerHour' => 'sometimes|string|max:8',
                'repeatHours' => 'sometimes|integer|min:1',
                'active' => 'sometimes|boolean',
            ]);
            $rule->update(array_filter([
                'trigger_hour' => $data['triggerHour'] ?? null,
                'repeat_hours' => $data['repeatHours'] ?? null,
                'active' => $data['active'] ?? null,
            ], fn ($v) => $v !== null));

            return $this->ok(['id' => $rule->id, 'active' => (bool) $rule->active]);
        });
    }

    public function deleteRule(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            AutoReminderRule::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    public function toggleRule(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $rule = AutoReminderRule::findOrFail($id);
            $rule->update(['active' => ! $rule->active]);

            return $this->ok(['id' => $rule->id, 'active' => (bool) $rule->active]);
        });
    }
}
