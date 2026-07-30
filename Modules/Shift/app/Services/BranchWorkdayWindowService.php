<?php

namespace Modules\Shift\Services;

use Modules\Shift\Models\Shift;

/**
 * The branch manager's workday window = the branch's WHOLE day of cashier
 * shifts, not a fixed 8-hour block.
 *
 * A manager supervises every shift the branch runs that day, so their "My
 * Shift" card must start at the first shift window, end at the last one, and
 * count the SUM of the day's shift hours (3 × 8h → 24h, 4 × 6h → 24h). The
 * screens used to hard-code 09:00–17:00 / 8h, which made the progress bar and
 * the elapsed/total hours wrong on every branch.
 *
 * Windows wrap past midnight (16:00 → 00:00), so durations are computed in
 * minutes modulo the day; a `start == end` template means a full 24 hours.
 */
class BranchWorkdayWindowService
{
    private const MINUTES_PER_DAY = 1440;

    /** Fallback when a branch has no shift templates yet (legacy branches). */
    private const FALLBACK = ['start' => '09:00', 'end' => '17:00', 'totalHours' => 8.0, 'shiftCount' => 0];

    /**
     * @return array{start: string, end: string, totalHours: float, shiftCount: int}
     */
    public function forBranch(?string $branchId): array
    {
        if ($branchId === null) {
            return self::FALLBACK;
        }

        $shifts = Shift::where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get(['start_time', 'end_time']);

        if ($shifts->isEmpty()) {
            return self::FALLBACK;
        }

        $totalMinutes = 0;
        foreach ($shifts as $shift) {
            $totalMinutes += $this->windowMinutes(
                $this->toMinutes($shift->start_time?->format('H:i')),
                $this->toMinutes($shift->end_time?->format('H:i')),
            );
        }

        // A branch cannot run more than a day of shifts; overlapping templates
        // would otherwise inflate the manager's workday beyond 24 hours.
        $totalMinutes = min($totalMinutes, self::MINUTES_PER_DAY);

        $startMinutes = $this->toMinutes($shifts->first()->start_time?->format('H:i'));

        return [
            'start' => $this->toHHMM($startMinutes),
            'end' => $this->toHHMM(($startMinutes + $totalMinutes) % self::MINUTES_PER_DAY),
            'totalHours' => round($totalMinutes / 60, 2),
            'shiftCount' => $shifts->count(),
        ];
    }

    /** Planned workday length in hours — the expected duration of a manager shift. */
    public function totalHoursForBranch(?string $branchId): float
    {
        return $this->forBranch($branchId)['totalHours'];
    }

    /** Length of one window, wrapping past midnight; start == end means a full day. */
    private function windowMinutes(int $start, int $end): int
    {
        $span = ($end - $start + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;

        return $span === 0 ? self::MINUTES_PER_DAY : $span;
    }

    private function toMinutes(?string $hhmm): int
    {
        if (! $hhmm) {
            return 0;
        }
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h * 60 + (int) $m) % self::MINUTES_PER_DAY;
    }

    private function toHHMM(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
