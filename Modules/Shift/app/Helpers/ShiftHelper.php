<?php

namespace Modules\Shift\Helpers;

use Carbon\Carbon;

class ShiftHelper
{
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
