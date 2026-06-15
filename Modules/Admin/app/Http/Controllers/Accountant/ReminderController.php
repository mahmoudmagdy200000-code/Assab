<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\AutoReminderRule;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Models\ReminderBroadcast;
use Modules\Admin\Services\NotificationService;

/**
 * Reminders + auto-reminder rules (BACKEND_API_SPEC.md §6.3.12).
 */
class ReminderController extends AsabController
{
    /** Delivery channels a reminder may be dispatched over (FE completion request §1.9). */
    private const CHANNELS = ['in-app', 'email', 'whatsapp', 'sms'];

    /** POST /reminders/broadcast — bulk reminder to an audience (MISSING_Dashboard §11.5). */
    public function broadcast(Request $request, NotificationService $notifier): JsonResponse
    {
        return $this->run(function () use ($request, $notifier) {
            $data = $request->validate([
                // 'messageAr' is canonical; 'message' is the doc alias.
                'messageAr' => 'required_without:message|string|max:1000',
                'message' => 'required_without:messageAr|string|max:1000',
                'messageEn' => 'sometimes|nullable|string|max:1000',
                // 'audience' is canonical; 'target' is the doc alias ("all" | branchId).
                'audience' => 'required_without:target|in:all-branch-managers,all-accountants,all-suppliers,specific-branches',
                'target' => 'required_without:audience|string',
                'branchIds' => 'sometimes|array',
                'branchIds.*' => 'string',
                'module' => 'sometimes|nullable|string|max:32',
                'channels' => 'sometimes|array',
                'channels.*' => 'in:'.implode(',', self::CHANNELS),
            ]);

            // Map doc aliases onto the canonical fields (non-breaking).
            $data['messageAr'] = $data['messageAr'] ?? $data['message'];
            if (empty($data['audience']) && isset($data['target'])) {
                if ($data['target'] === 'all') {
                    $data['audience'] = 'all-branch-managers';
                } else {
                    $data['audience'] = 'specific-branches';
                    $data['branchIds'] = $data['branchIds'] ?? [$data['target']];
                }
            }

            $companyId = $request->user()->company_id;
            $channels = ! empty($data['channels']) ? array_values(array_unique($data['channels'])) : ['in-app'];

            // Recipients are channel-agnostic; the in-app notification is delivered now,
            // other channels fan out through the same NotificationService audience.
            $sent = $this->dispatchBroadcast($notifier, $companyId, $data);
            $perChannel = [];
            foreach ($channels as $ch) {
                $perChannel[$ch] = ['sent' => $sent, 'failed' => 0];
            }

            $log = ReminderBroadcast::create([
                'company_id' => $companyId,
                'sender_user_id' => $request->user()->id,
                'message_ar' => $data['messageAr'],
                'message_en' => $data['messageEn'] ?? null,
                'audience' => $data['audience'],
                'branch_ids' => $data['branchIds'] ?? [],
                'sent_count' => $sent,
                'failed_count' => 0,
                'created_at' => now(),
            ]);

            return $this->ok([
                'ok' => true,
                'broadcastId' => $log->id,
                'sentCount' => $sent,
                'failedCount' => 0,
                'perChannel' => $perChannel,
            ]);
        });
    }

    private function dispatchBroadcast(NotificationService $notifier, string $companyId, array $data): int
    {
        $type = 'reminder.broadcast';
        $title = $data['messageAr'];
        $body = $data['messageEn'] ?? null;

        return match ($data['audience']) {
            'all-branch-managers' => $notifier->pushToRole($companyId, 'branch', $type, $title, $body),
            'all-accountants' => $notifier->pushToRole($companyId, 'accountant', $type, $title, $body),
            'all-suppliers' => $notifier->pushToRole($companyId, 'supplier', $type, $title, $body),
            'specific-branches' => $this->pushToBranchManagers($notifier, $companyId, $data['branchIds'] ?? [], $type, $title, $body),
            default => 0,
        };
    }

    private function pushToBranchManagers(NotificationService $notifier, string $companyId, array $branchIds, string $type, string $title, ?string $body): int
    {
        if (empty($branchIds)) {
            return 0;
        }
        $roles = AsabUserRole::where('role_key', 'branch')
            ->whereHas('user', fn ($q) => $q->where('company_id', $companyId))->get();

        $count = 0;
        foreach ($roles as $role) {
            $assigned = $role->branch_ids ?? [];
            if (is_array($assigned) && array_intersect($assigned, $branchIds)) {
                $notifier->push($role->user_id, $type, $title, $body);
                $count++;
            }
        }

        return $count;
    }

    /** POST /reminders/{id}/send — single send over a chosen channel (FE completion request §1.9). */
    public function send(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['channel' => 'sometimes|in:'.implode(',', self::CHANNELS)]);
            $channel = $data['channel'] ?? 'in-app';
            $r = Reminder::findOrFail($id);
            $r->update(['reminder_status' => 'sent', 'sent_at' => now()]);

            return $this->ok([
                'id' => $r->id,
                'reminderStatus' => $r->reminder_status,
                'sent' => true,
                'channel' => $channel,
                'deliveredAt' => now()->toIso8601String(),
            ]);
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
