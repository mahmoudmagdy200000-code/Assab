<?php

namespace Modules\Shift\Helpers;

use Carbon\Carbon;
use Modules\Shift\Models\CashierShift;

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
        $workDays = self::workDays();

        return array_values(array_filter(
            self::workWeekDateSequence($date),
            fn (Carbon $d) => in_array($d->dayOfWeek, $workDays, true) && ! self::isHoliday($d)
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
        return number_format($amount, 2).' '.$currency;
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

    /**
     * Unified start window for a cashier shift:
     * - Window opens 2 hours before scheduled start_time
     * - Window closes 2 hours after scheduled end_time
     * - Midnight crossings (end_time <= start_time) automatically extend to the following calendar day
     * - All evaluations use the official branch timezone (default Asia/Riyadh)
     */
    public static function getShiftStartWindow(CashierShift $cashierShift, ?Carbon $now = null): array
    {
        $timezone = config('app.timezone', 'Asia/Riyadh');
        $now = $now ? $now->copy()->setTimezone($timezone) : Carbon::now($timezone);

        $shiftDate = $cashierShift->shift_date instanceof Carbon
            ? $cashierShift->shift_date->format('Y-m-d')
            : (string) $cashierShift->shift_date;

        $shiftTemplate = $cashierShift->shift;
        if (! $shiftTemplate) {
            return [
                'can_start' => false,
                'reason' => 'بيانات قالب الوردية غير متوفرة',
                'window_opens_at' => null,
                'window_closes_at' => null,
                'scheduled_start' => null,
                'scheduled_end' => null,
            ];
        }

        $startTime = $shiftTemplate->start_time instanceof Carbon
            ? $shiftTemplate->start_time->format('H:i')
            : (string) $shiftTemplate->start_time;
        $endTime = $shiftTemplate->end_time instanceof Carbon
            ? $shiftTemplate->end_time->format('H:i')
            : (string) $shiftTemplate->end_time;

        $startTime = substr(trim($startTime), 0, 5);
        $endTime = substr(trim($endTime), 0, 5);

        if (empty($startTime)) {
            $startTime = '00:00';
        }
        if (empty($endTime)) {
            $endTime = '23:59';
        }

        $scheduledStart = Carbon::createFromFormat('Y-m-d H:i', "{$shiftDate} {$startTime}", $timezone);
        $scheduledEnd = Carbon::createFromFormat('Y-m-d H:i', "{$shiftDate} {$endTime}", $timezone);

        // Midnight crossing: when end_time <= start_time, the shift ends on the next calendar day
        if ($scheduledEnd->lte($scheduledStart)) {
            $scheduledEnd->addDay();
        }

        // Start window opens 2 hours before scheduled start, and closes 2 hours after scheduled end
        $windowOpensAt = $scheduledStart->copy()->subHours(2);
        $windowClosesAt = $scheduledEnd->copy()->addHours(2);

        if ($now->lt($windowOpensAt)) {
            $opensAtFormatted = $windowOpensAt->format('H:i');

            return [
                'can_start' => false,
                'reason' => "لا يمكن بدء الوردية قبل موعدها بأكثر من ساعتين (تفتح في {$opensAtFormatted})",
                'window_opens_at' => $windowOpensAt->toIso8601String(),
                'window_closes_at' => $windowClosesAt->toIso8601String(),
                'scheduled_start' => $scheduledStart->toIso8601String(),
                'scheduled_end' => $scheduledEnd->toIso8601String(),
                'server_time' => $now->toIso8601String(),
            ];
        }

        if ($now->gte($windowClosesAt)) {
            $closesAtFormatted = $windowClosesAt->format('H:i');

            return [
                'can_start' => false,
                'reason' => "انتهت نافذة بدء الوردية (أُغلقت في {$closesAtFormatted})",
                'window_opens_at' => $windowOpensAt->toIso8601String(),
                'window_closes_at' => $windowClosesAt->toIso8601String(),
                'scheduled_start' => $scheduledStart->toIso8601String(),
                'scheduled_end' => $scheduledEnd->toIso8601String(),
                'server_time' => $now->toIso8601String(),
            ];
        }

        return [
            'can_start' => true,
            'reason' => null,
            'window_opens_at' => $windowOpensAt->toIso8601String(),
            'window_closes_at' => $windowClosesAt->toIso8601String(),
            'scheduled_start' => $scheduledStart->toIso8601String(),
            'scheduled_end' => $scheduledEnd->toIso8601String(),
            'server_time' => $now->toIso8601String(),
        ];
    }
}
