<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\BranchShiftConfig;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Models\Shift;
use Modules\Branch\Models\Branch;

/**
 * SRS ACC-6.2 — an `active` shift whose configured window has elapsed becomes
 * `late`, so the live tab's «انتهى وقت الشفت» banner and the overdue list have a
 * data source. Idempotent: only active shifts past their end flip, and each
 * flip broadcasts once.
 *
 * No facades — a plain service the scheduled command (and tests) drive directly.
 */
class ShiftLatenessService
{
    public function __construct(private readonly RealtimeBroadcaster $rt, private readonly ShiftConfigService $configs) {}

    /**
     * Mark every overdue active shift `late`. Returns the number flipped.
     */
    public function markLate(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        // Till shifts only: a manager's mirrored row covers the WHOLE workday,
        // so judging it against one shift window would flip it «تأخير» hours
        // before the manager is actually late.
        $shifts = Shift::withoutGlobalScopes()->cashierRole()
            ->where('status', 'active')->whereNotNull('started_at')->get();
        if ($shifts->isEmpty()) {
            return 0;
        }

        $branchIds = $shifts->pluck('branch_id')->filter()->unique();
        $brandByBranch = Branch::whereIn('id', $branchIds)->pluck('asab_brand_id', 'id');
        $configs = BrandShiftConfig::whereIn('brand_id', $brandByBranch->filter()->unique())->get()->keyBy('brand_id');
        // A branch running its own schedule is judged against ITS windows.
        $branchConfigs = BranchShiftConfig::whereIn('branch_id', $branchIds)->get()->keyBy('branch_id');

        $flipped = 0;
        foreach ($shifts as $shift) {
            $config = $branchConfigs->get($shift->branch_id)
                ?? $configs->get($brandByBranch[$shift->branch_id] ?? null);
            if ($this->isOverdue($shift, $config, $now)) {
                $shift->update(['status' => 'late']);
                $this->rt->shiftChanged($shift->fresh(), 'late');
                $flipped++;
            }
        }

        return $flipped;
    }

    /**
     * A shift is overdue when its window end (from the effective config —
     * branch override first, else the brand's — matched by shift number) is in
     * the past. Fallback: `started_at + duration`.
     */
    public function isOverdue(Shift $shift, BrandShiftConfig|BranchShiftConfig|null $config, Carbon $now): bool
    {
        return $now->greaterThan($this->windowEnd($shift, $config));
    }

    private function windowEnd(Shift $shift, BrandShiftConfig|BranchShiftConfig|null $config): Carbon
    {
        $start = Carbon::parse($shift->started_at);
        $minutes = $this->configs->durationMinutes($config);

        // Prefer the configured window end for the shift's number; else duration.
        if ($config !== null && $shift->shift_no !== null) {
            $windows = collect($this->configs->windowsFromMinutes(
                $config->num_shifts ?? 2,
                $minutes,
                $config->first_shift_start ?? '06:00',
                $this->configs->overrides(is_array($config->shifts) ? $config->shifts : []),
            ));
            $window = $windows->firstWhere('no', $shift->shift_no);
            if ($window !== null) {
                [$h, $m] = array_pad(explode(':', $window['end']), 2, '0');
                $end = $start->copy()->setTime((int) $h, (int) $m);
                // End before start means the window wrapped past midnight.
                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                return $end;
            }
        }

        return $start->copy()->addMinutes($minutes);
    }
}
