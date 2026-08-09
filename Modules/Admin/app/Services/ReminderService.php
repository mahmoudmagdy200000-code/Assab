<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AutoReminderRule;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Branch\Models\Branch;

/**
 * §6.3.12 «التذكيرات — بيانات الفروع المفقودة».
 *
 * The screen read `asab_reminders` and the auto-rule toggles wrote
 * `asab_auto_reminder_rules`, but NOTHING ever created a reminder row and
 * nothing ever consumed a rule: the list was permanently empty, every KPI read
 * 0, and «إرسال» only flipped a status without notifying anyone. This service
 * is the missing half.
 *
 *  - {@see generate()} — for a day, one reminder per (branch × required module)
 *    with no data; idempotent, and it RESOLVES reminders whose data has since
 *    arrived so the list never lies.
 *  - {@see dispatchDue()} — the rules engine: a rule fires at its `trigger_hour`
 *    and re-fires every `repeat_hours` while nobody has answered.
 *  - {@see send()} — actually notifies the branch's manager(s).
 */
class ReminderService
{
    /** Modules a branch owes DAILY (the rules screen's four rows). */
    public const MODULES = ['sales', 'inventory', 'waste', 'expenses'];

    /**
     * Seeded once per company, then owned by the toggles on the screen.
     * Hours/repeats match the delivered UI so the first read is not empty.
     */
    public const DEFAULT_RULES = [
        ['module' => 'sales', 'trigger_hour' => '22:00', 'repeat_hours' => 2],
        ['module' => 'inventory', 'trigger_hour' => '20:00', 'repeat_hours' => 3],
        ['module' => 'waste', 'trigger_hour' => '21:00', 'repeat_hours' => 4],
        ['module' => 'expenses', 'trigger_hour' => '23:00', 'repeat_hours' => 2],
    ];

    /** How far back {@see daysMissing()} counts a run of empty days. */
    private const MISSING_LOOKBACK_DAYS = 14;

    public function __construct(
        private readonly NotificationService $notifier,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    /**
     * The company's auto-reminder rules, seeding the defaults the first time.
     * Idempotent — a company that already has a row for a module keeps it.
     *
     * @return Collection<int, AutoReminderRule>
     */
    public function ensureRules(string $companyId): Collection
    {
        $existing = AutoReminderRule::where('company_id', $companyId)->get();
        $have = $existing->pluck('module')->all();

        foreach (self::DEFAULT_RULES as $rule) {
            if (in_array($rule['module'], $have, true)) {
                continue;
            }
            $existing->push(AutoReminderRule::create([
                'company_id' => $companyId,
                'module' => $rule['module'],
                'trigger_hour' => $rule['trigger_hour'],
                'repeat_hours' => $rule['repeat_hours'],
                'active' => true,
            ]));
        }

        return $existing->sortBy('trigger_hour')->values();
    }

    /**
     * Create/refresh the missing-data reminders for one day.
     *
     * @param  string[]|null  $branchIds  null = every branch of the company
     * @return array{created:int, resolved:int, branches:int, modules:int}
     */
    public function generate(string $companyId, ?string $date = null, ?array $branchIds = null): array
    {
        $day = Carbon::parse($date ?? 'today')->startOfDay();
        $rules = $this->ensureRules($companyId)->where('active', true);
        $modules = $rules->pluck('module')->intersect(self::MODULES)->values();

        $branches = Branch::query()
            ->where('asab_company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->get(['id', 'name']);

        if ($branches->isEmpty() || $modules->isEmpty()) {
            return ['created' => 0, 'resolved' => 0, 'branches' => $branches->count(), 'modules' => $modules->count()];
        }

        // One query for the whole day's coverage instead of branch × module.
        $present = $this->coverage($companyId, $branches->pluck('id')->all(), $day, $day);
        $history = $this->coverage(
            $companyId,
            $branches->pluck('id')->all(),
            (clone $day)->subDays(self::MISSING_LOOKBACK_DAYS),
            $day,
        );

        $existing = Reminder::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $branches->pluck('id'))
            ->whereDate('required_by', $day->toDateString())
            ->get()
            ->keyBy(fn (Reminder $r) => $r->branch_id.'|'.$r->module_key);

        $seq = $this->nextSequence();
        $created = 0;
        $resolved = 0;

        foreach ($branches as $branch) {
            foreach ($modules as $module) {
                $key = $branch->id.'|'.$module;
                $has = isset($present[$branch->id][$module]);
                $row = $existing->get($key);

                if ($has) {
                    // The data landed after the reminder was raised — close it
                    // rather than leaving a permanent false «مفقود».
                    if ($row !== null && $row->reminder_status !== 'responded') {
                        $row->update([
                            'reminder_status' => 'responded',
                            'response' => 'وصلت البيانات',
                            'responded_at' => now(),
                        ]);
                        $resolved++;
                    }

                    continue;
                }
                if ($row !== null) {
                    // Refresh the age so «متأخر 3 أيام» stays true.
                    $row->update(['days_missing' => $this->daysMissing($history, $branch->id, $module, $day)]);

                    continue;
                }

                $rule = $rules->firstWhere('module', $module);
                $days = $this->daysMissing($history, $branch->id, $module, $day);

                Reminder::create([
                    'company_id' => $companyId,
                    'public_id' => 'REM-'.str_pad((string) $seq++, 4, '0', STR_PAD_LEFT),
                    'branch_id' => $branch->id,
                    'report_type' => 'daily_'.$module,
                    'module_key' => $module,
                    'required_by' => $this->requiredBy($day, $rule?->trigger_hour),
                    'days_missing' => $days,
                    'urgency' => $this->urgency($days),
                    'reminder_status' => 'not_sent',
                    'message' => $this->message($branch->name, $module, $day),
                ]);
                $created++;
            }
        }

        return [
            'created' => $created,
            'resolved' => $resolved,
            'branches' => $branches->count(),
            'modules' => $modules->count(),
        ];
    }

    /**
     * The rules engine. A reminder's FIRST send waits for its day's
     * `trigger_hour`; after that it re-fires every `repeat_hours` — including
     * past midnight, because yesterday's missing sales sheet is still missing at
     * 01:00. Returns the number of reminders actually sent.
     */
    public function dispatchDue(string $companyId, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $rules = $this->ensureRules($companyId)->where('active', true);
        $sent = 0;

        foreach ($rules as $rule) {
            $repeat = max(1, (int) $rule->repeat_hours);
            $reminders = Reminder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('module_key', $rule->module)
                ->whereIn('reminder_status', ['not_sent', 'sent'])
                ->whereDate('required_by', '<=', $now->toDateString())
                ->limit(500)
                ->get();

            foreach ($reminders as $reminder) {
                if ($reminder->reminder_status === 'not_sent') {
                    // Read the hour off the LIVE rule so moving it takes effect
                    // immediately, not only on tomorrow's batch.
                    $day = $reminder->required_by !== null
                        ? $reminder->required_by->copy()->startOfDay()
                        : $now->copy()->startOfDay();
                    if ($now->lessThan($this->requiredBy($day, $rule->trigger_hour))) {
                        continue; // too early on its day
                    }
                } elseif ($reminder->sent_at !== null
                    && $reminder->sent_at->copy()->addHours($repeat)->greaterThan($now)) {
                    continue; // inside the repeat window
                }

                if ($this->send($reminder, null, 'in-app', $now)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Deliver one reminder to the branch's manager(s) and stamp it sent.
     * `$at` is the effective send time (the engine passes its own clock so the
     * repeat window is measured against the run, not the wall clock).
     * Returns false when there is nobody to notify (no branch on the row).
     */
    public function send(Reminder $reminder, ?AsabUser $actor = null, string $channel = 'in-app', ?Carbon $at = null): bool
    {
        $title = $reminder->message ?: $this->message(null, $reminder->module_key, $reminder->required_by);

        $recipients = 0;
        if ($reminder->branch_id !== null) {
            $recipients = $this->notifier->pushToBranch(
                $reminder->company_id,
                $reminder->branch_id,
                'branch',
                'reminder.missing-data',
                $title,
                $reminder->days_missing > 1 ? 'متأخر '.$reminder->days_missing.' أيام' : null,
                null,
                ['type' => 'reminder', 'id' => $reminder->id],
            );
        }

        $reminder->update([
            'reminder_status' => 'sent',
            'sent_at' => $at ?? now(),
        ]);
        $this->rt->reminderSent($reminder->fresh());

        return $recipients > 0 || $reminder->branch_id !== null;
    }

    /**
     * «إرسال للكل» / bulk send. `$ids` null = every not-yet-answered reminder in
     * the given scope.
     *
     * @param  string[]|null  $ids
     * @param  array{moduleKey?:?string, branchIds?:?array, onlyUnsent?:bool}  $scope
     * @return array{sent:int, skipped:int}
     */
    public function sendMany(string $companyId, ?array $ids, array $scope = [], ?AsabUser $actor = null, string $channel = 'in-app'): array
    {
        $q = Reminder::withoutGlobalScopes()->where('company_id', $companyId);

        if ($ids !== null && $ids !== []) {
            $q->whereIn('id', $ids);
        } elseif ($scope['onlyUnsent'] ?? true) {
            $q->where('reminder_status', 'not_sent');
        } else {
            $q->whereIn('reminder_status', ['not_sent', 'sent']);
        }
        if (! empty($scope['moduleKey'])) {
            $q->where('module_key', $scope['moduleKey']);
        }
        if (($scope['branchIds'] ?? null) !== null) {
            $q->whereIn('branch_id', $scope['branchIds']);
        }

        $reminders = $q->limit(1000)->get();
        $sent = 0;
        foreach ($reminders as $reminder) {
            if ($reminder->reminder_status === 'responded') {
                continue;
            }
            if ($this->send($reminder, $actor, $channel)) {
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $reminders->count() - $sent];
    }

    /** @return array<string, mixed> the list projection of one reminder */
    public function present(Reminder $r, array $branchNames = []): array
    {
        return [
            'id' => $r->id,
            'publicId' => $r->public_id,
            'branchId' => $r->branch_id,
            'branchName' => $branchNames[$r->branch_id] ?? null,
            'reportType' => $r->report_type,
            'moduleKey' => $r->module_key,
            'moduleLabelAr' => ModuleCatalog::labelAr((string) $r->module_key),
            'message' => $r->message,
            'urgency' => $r->urgency,
            'reminderStatus' => $r->reminder_status,
            'response' => $r->response,
            'daysMissing' => (int) $r->days_missing,
            'requiredBy' => optional($r->required_by)->toIso8601String(),
            'sentAt' => optional($r->sent_at)->toIso8601String(),
            'respondedAt' => optional($r->responded_at)->toIso8601String(),
        ];
    }

    /**
     * KPI header: «لم تُرسل بعد / تم الإرسال / تم الرد / إجمالي المفقود».
     * `totalMissing` counts what is STILL missing (answered rows are not).
     *
     * @param  Collection<int, Reminder>  $items
     */
    public function summary(Collection $items): array
    {
        $notSent = $items->where('reminder_status', 'not_sent')->count();
        $sent = $items->where('reminder_status', 'sent')->count();

        return [
            'notSent' => $notSent,
            'sent' => $sent,
            'responded' => $items->where('reminder_status', 'responded')->count(),
            'totalMissing' => $notSent + $sent,
            'total' => $items->count(),
        ];
    }

    /**
     * branchId → moduleKey → 'Y-m-d' → true, for the branch-days that DO carry
     * data. One grouped query for the whole window.
     *
     * @param  string[]  $branchIds
     * @return array<string, array<string, array<string, bool>>>
     */
    private function coverage(string $companyId, array $branchIds, Carbon $from, Carbon $to): array
    {
        if ($branchIds === []) {
            return [];
        }

        $rows = Operation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('module_key', self::MODULES)
            ->whereBetween('operation_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('branch_id, module_key, DATE(operation_date) as day')
            ->groupBy('branch_id', 'module_key', 'day')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->branch_id][$row->module_key][substr((string) $row->day, 0, 10)] = true;
        }

        return $out;
    }

    /** Consecutive days without data ending on `$day` (1 = only today). */
    private function daysMissing(array $history, string $branchId, string $module, Carbon $day): int
    {
        $days = 0;
        for ($i = 0; $i <= self::MISSING_LOOKBACK_DAYS; $i++) {
            $date = $day->copy()->subDays($i)->toDateString();
            if (isset($history[$branchId][$module][$date])) {
                break;
            }
            $days++;
        }

        return max(1, $days);
    }

    private function urgency(int $daysMissing): string
    {
        return match (true) {
            $daysMissing >= 3 => 'high',
            $daysMissing >= 2 => 'medium',
            default => 'low',
        };
    }

    private function requiredBy(Carbon $day, ?string $triggerHour): Carbon
    {
        [$h, $m] = array_pad(explode(':', (string) ($triggerHour ?: '22:00')), 2, '0');

        return $day->copy()->setTime((int) $h, (int) $m);
    }

    private function message(?string $branchName, ?string $module, Carbon|string|null $day): string
    {
        $label = ModuleCatalog::labelAr((string) $module);
        $date = $day instanceof Carbon ? $day->toDateString() : (string) $day;
        $where = $branchName ? " لفرع {$branchName}" : '';

        return "لم يتم رفع بيانات {$label}{$where} ليوم {$date}";
    }

    /** Next REM-#### number. Reminders are generated by one scheduled writer. */
    private function nextSequence(): int
    {
        $last = Reminder::withoutGlobalScopes()
            ->where('public_id', 'like', 'REM-%')
            ->orderByRaw('LENGTH(public_id) DESC')
            ->orderBy('public_id', 'desc')
            ->value('public_id');

        return $last ? ((int) substr($last, 4)) + 1 : 1;
    }
}
