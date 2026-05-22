<?php

namespace Modules\BranchManagers\Support;

use Carbon\Carbon;
use Modules\Branch\Models\Branch;

/**
 * Formats a branch's operating window into the human readable strings
 * used by the branch manager settings screen.
 *
 * Time values are read from the raw branch attributes so that legacy or
 * malformed values never break the response via the model's datetime cast.
 */
class BranchScheduleFormatter
{
    /**
     * Default work week for the Saudi market (Asia/Riyadh).
     */
    public const WORK_WEEK = 'Sun - Thu';

    public static function weekdays(): string
    {
        return self::WORK_WEEK;
    }

    /**
     * Labelled work shift, e.g. "Morning (8:00 AM - 10:00 PM)".
     * Returns null when the branch has no usable operating hours.
     */
    public static function workShift(?Branch $branch): ?string
    {
        $hours = self::parsedHours($branch);

        if ($hours === null) {
            return null;
        }

        $period = match (true) {
            $hours['open']->hour < 12 => 'Morning',
            $hours['open']->hour < 17 => 'Afternoon',
            default => 'Evening',
        };

        return sprintf(
            '%s (%s - %s)',
            $period,
            $hours['open']->format('g:i A'),
            $hours['close']->format('g:i A'),
        );
    }

    /**
     * Branch opening summary, e.g. "Sun - Thu / 8:00 AM - 10:00 PM".
     */
    public static function branchOpening(?Branch $branch): string
    {
        $hours = self::parsedHours($branch);

        if ($hours === null) {
            return self::WORK_WEEK;
        }

        return sprintf(
            '%s / %s - %s',
            self::WORK_WEEK,
            $hours['open']->format('g:i A'),
            $hours['close']->format('g:i A'),
        );
    }

    /**
     * @return array{open: Carbon, close: Carbon}|null
     */
    private static function parsedHours(?Branch $branch): ?array
    {
        if ($branch === null) {
            return null;
        }

        $open = self::parseTime($branch->getRawOriginal('opening_hours'));
        $close = self::parseTime($branch->getRawOriginal('closing_hours'));

        if ($open === null || $close === null) {
            return null;
        }

        return ['open' => $open, 'close' => $close];
    }

    /**
     * Parse a raw branch hour value (time string or datetime string).
     * Legacy/malformed values yield null instead of breaking the response.
     */
    private static function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
