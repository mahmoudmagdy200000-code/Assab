<?php

namespace Modules\Shift\Helpers;

use Carbon\Carbon;

class ShiftHelper
{
    private const DAY_MAP = [
        0 => Carbon::SUNDAY,
        1 => Carbon::MONDAY,
        2 => Carbon::TUESDAY,
        3 => Carbon::WEDNESDAY,
        4 => Carbon::THURSDAY,
        5 => Carbon::FRIDAY,
        6 => Carbon::SATURDAY,
    ];

    /**
     * Current work week [start, end] as Carbon instances.
     * Week starts on configured day (default Sunday); end = last work day (default Thursday).
     */
    public static function currentWorkWeekDates(): array
    {
        return self::workWeekDatesFor(Carbon::today());
    }

    /**
     * Next work week [start, end] (the week after the one containing $date).
     */
    public static function nextWorkWeekDates(Carbon $date): array
    {
        [$start] = self::workWeekDatesFor($date);

        return self::workWeekDatesFor($start->copy()->addWeek());
    }

    /**
     * Get [start, end] for the work week containing the given date.
     */
    public static function workWeekDatesFor(Carbon $date): array
    {
        $weekStart = (int) config('shift.week.week_start', 0);
        $workDays = config('shift.week.work_days', [0, 1, 2, 3, 4]);
        $carbonDay = self::DAY_MAP[$weekStart] ?? Carbon::SUNDAY;

        $start = $date->copy()->startOfWeek($carbonDay);
        $end = $start->copy()->addDays(max($workDays));

        return [$start, $end];
    }

    /**
     * Iterate over each date in the work week containing $date.
     *
     * @return array<int, Carbon>
     */
    public static function workWeekDateSequence(Carbon $date): array
    {
        [$start, $end] = self::workWeekDatesFor($date);
        $out = [];
        $cur = $start->copy();
        while ($cur->lte($end)) {
            $out[] = $cur->copy();
            $cur->addDay();
        }

        return $out;
    }

    /**
     * Work week dates for the week containing $date, excluding holidays.
     *
     * @return array<int, Carbon>
     */
    public static function workWeekDatesExcludingHolidays(Carbon $date): array
    {
        return array_values(array_filter(
            self::workWeekDateSequence($date),
            fn (Carbon $d) => ! self::isHoliday($d)
        ));
    }

    /**
     * Check if the given date is a configured holiday.
     */
    public static function isHoliday(Carbon|string $date): bool
    {
        $d = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        return in_array($d, config('shift.holidays', []), true);
    }

    /**
     * Work days (0–6) for the current config.
     */
    public static function workDays(): array
    {
        return config('shift.week.work_days', [0, 1, 2, 3, 4]);
    }

    public static function formatCurrency(float $amount, string $currency = 'SAR'): string
    {
        return number_format($amount, 2) . ' ' . $currency;
    }

    public static function calculateVAT(float $amount, float $percentage = 15): float
    {
        return round($amount * ($percentage / 100), 2);
    }

    public static function calculateNetAmount(float $total, float $vat): float
    {
        return round($total - $vat, 2);
    }

    public static function formatShiftDuration(Carbon $start, Carbon $end): string
    {
        $diff = $start->diff($end);
        return sprintf('%d hours %d minutes', $diff->h, $diff->i);
    }

    public static function isShiftOvertime(Carbon $scheduledEnd, Carbon $actualEnd): bool
    {
        return $actualEnd->greaterThan($scheduledEnd->addMinutes(15));
    }

    public static function getVarianceColor(float $variance): string
    {
        if ($variance > 0) {
            return 'green'; // Over
        } elseif ($variance < 0) {
            return 'red'; // Short
        }
        return 'gray'; // No variance
    }

    public static function getStatusBadgeColor(string $status): string
    {
        return match ($status) {
            'not_started' => 'gray',
            'in_progress' => 'blue',
            'completed' => 'green',
            'reassigned' => 'orange',
            default => 'gray',
        };
    }
}
