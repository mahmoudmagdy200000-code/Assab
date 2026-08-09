<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\AutoReminderRule;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Models\ReminderBroadcast;
use Modules\Admin\Services\BrandBranchResolver;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\ReminderService;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Branch\Models\Branch;

/**
 * «التذكيرات — بيانات الفروع المفقودة» + the auto-rule toggles
 * (BACKEND_API_SPEC.md §6.3.12). The list, the KPI header, single/bulk send and
 * the rules all live here; the engine is {@see ReminderService}.
 */
class ReminderController extends AsabController
{
    /** Delivery channels a reminder may be dispatched over (FE completion request §1.9). */
    private const CHANNELS = ['in-app', 'email', 'whatsapp', 'sms'];

    /** Hard cap on one list read — the screen is a working queue, not an archive. */
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly ReminderService $reminders,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /**
     * GET …/reminders — the missing-data queue.
     * Filters: `moduleKey`، `brandId`، `branchId`، `status`، `q` (branch name).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'status' => 'sometimes|nullable|in:not_sent,sent,responded',
                'moduleKey' => 'sometimes|nullable|string|max:32',
            ]);

            $q = $this->scopeToAssignedBranches(Reminder::query());
            $this->brandBranches->applyFilter($q, $request->query('brandId'));

            if ($module = $request->query('moduleKey', $request->query('module'))) {
                $q->where('module_key', $module);
            }
            if ($branchId = $request->query('branchId')) {
                $q->where('branch_id', $branchId);
            }
            if ($status = $request->query('status')) {
                $q->where('reminder_status', $status);
            }
            if ($needle = trim((string) $request->query('q', ''))) {
                $branchIds = Branch::where('name', 'like', '%'.$needle.'%')->pluck('id');
                $q->whereIn('branch_id', $branchIds);
            }

            $items = $q->orderByDesc('required_by')->orderByDesc('created_at')->limit(self::MAX_ROWS)->get();
            $branchNames = $this->branchNames($items->pluck('branch_id'));

            return $this->listResponse(
                $items->map(fn (Reminder $r) => $this->reminders->present($r, $branchNames))->all(),
                [
                    'summary' => $this->reminders->summary($items),
                    'modules' => array_map(
                        fn (string $key) => ['key' => $key, 'labelAr' => ModuleCatalog::labelAr($key)],
                        ReminderService::MODULES,
                    ),
                    'capped' => $items->count() >= self::MAX_ROWS,
                ],
            );
        });
    }

    /**
     * POST …/reminders/scan — rebuild today's missing-data list on demand
     * («تحديث»). The scheduler runs the same pass nightly.
     */
    public function scan(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['date' => 'sometimes|nullable|date']);

            $result = $this->reminders->generate(
                $request->user()->company_id,
                $request->query('date', $request->input('date')),
                $this->assignedBranchIds(),
            );

            return $this->ok($result);
        });
    }

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

    /**
     * POST /reminders/{id}/send — deliver one reminder to the branch's
     * manager(s). This used to flip the status without notifying anybody, which
     * is why «التذكير لا يعمل».
     */
    public function send(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['channel' => 'sometimes|in:'.implode(',', self::CHANNELS)]);
            $channel = $data['channel'] ?? 'in-app';
            // Zero-trust: a reminder outside the caller's branches reads as absent.
            $r = $this->scopeToAssignedBranches(Reminder::query())->findOrFail($id);

            $this->reminders->send($r, $request->user(), $channel);
            $r->refresh();

            return $this->ok([
                'id' => $r->id,
                'reminderStatus' => $r->reminder_status,
                'sent' => true,
                'channel' => $channel,
                'deliveredAt' => optional($r->sent_at)->toIso8601String(),
            ]);
        });
    }

    /**
     * POST /reminders/bulk-send — «إرسال تذكير للكل». With `ids` it sends those;
     * without, every not-yet-sent reminder in the caller's scope (optionally
     * narrowed by module/brand/branch).
     */
    public function bulkSend(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'ids' => 'sometimes|array',
                'ids.*' => 'string',
                'moduleKey' => 'sometimes|nullable|string|max:32',
                'brandId' => 'sometimes|nullable|string',
                'branchId' => 'sometimes|nullable|string',
                'channel' => 'sometimes|in:'.implode(',', self::CHANNELS),
                // false = also re-send the ones already sent but unanswered.
                'onlyUnsent' => 'sometimes|boolean',
            ]);

            $result = $this->reminders->sendMany(
                $request->user()->company_id,
                $data['ids'] ?? null,
                [
                    'moduleKey' => $data['moduleKey'] ?? null,
                    'branchIds' => $this->targetBranchIds($data),
                    'onlyUnsent' => $data['onlyUnsent'] ?? true,
                ],
                $request->user(),
                $data['channel'] ?? 'in-app',
            );

            return $this->ok($result);
        });
    }

    /**
     * The branch ids a bulk send may touch: the caller's assigned scope,
     * narrowed by an optional brand/branch filter. null = unrestricted (admin).
     *
     * @return string[]|null
     */
    private function targetBranchIds(array $data): ?array
    {
        $assigned = $this->assignedBranchIds();

        $filter = null;
        if (! empty($data['branchId'])) {
            $filter = [$data['branchId']];
        } elseif (! empty($data['brandId'])) {
            $filter = $this->brandBranches->branchIds($data['brandId']);
        }

        if ($filter === null) {
            return $assigned;
        }

        return $assigned === null ? $filter : array_values(array_intersect($assigned, $filter));
    }

    public function respond(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $id) {
            $data = $request->validate(['response' => 'required|string|max:80']);
            $r = $this->scopeToAssignedBranches(Reminder::query())->findOrFail($id);
            $r->update(['reminder_status' => 'responded', 'response' => $data['response'], 'responded_at' => now()]);
            $rt->reminderResponded($r->fresh());

            return $this->ok(['id' => $r->id, 'reminderStatus' => $r->reminder_status, 'response' => $r->response]);
        });
    }

    /** GET …/reminders/rules — seeded on first read so the toggles persist. */
    public function rules(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->listResponse(
            $this->reminders->ensureRules($request->user()->company_id)->map(fn (AutoReminderRule $r) => [
                'id' => $r->id,
                'module' => $r->module,
                'moduleLabelAr' => ModuleCatalog::labelAr((string) $r->module),
                'triggerHour' => $r->trigger_hour,
                'repeatHours' => $r->repeat_hours,
                'active' => (bool) $r->active,
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
            // Zero-trust: a rule of another company is not editable here.
            $rule = $this->companyRule($request, $id);
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

    public function deleteRule(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $this->companyRule($request, $id)->delete();

            return $this->noContent();
        });
    }

    public function toggleRule(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $rule = $this->companyRule($request, $id);
            $rule->update(['active' => ! $rule->active]);

            return $this->ok(['id' => $rule->id, 'active' => (bool) $rule->active]);
        });
    }

    private function companyRule(Request $request, string $id): AutoReminderRule
    {
        return AutoReminderRule::where('company_id', $request->user()->company_id)->findOrFail($id);
    }

    /** @param  \Illuminate\Support\Collection<int, ?string>  $ids */
    private function branchNames($ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Branch::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
