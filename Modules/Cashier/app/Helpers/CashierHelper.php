<?php

namespace Modules\Cashier\Helpers;

use Modules\Cashier\Models\Cashier;

class CashierHelper
{
    /**
     * Get cashier by identifier (email or phone)
     */
    public static function findByIdentifier(string $identifier): ?Cashier
    {
        return Cashier::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();
    }

    /**
     * Format cashier status badge
     */
    public static function getStatusBadge(string $status): array
    {
        return [
            'text' => match($status) {
                'active' => 'Active',
                'pending' => 'Pending',
                'deactivated' => 'Deactivated',
                default => 'Unknown',
            },
            'color' => match($status) {
                'active' => 'green',
                'pending' => 'yellow',
                'deactivated' => 'red',
                default => 'gray',
            },
            'icon' => match($status) {
                'active' => 'check-circle',
                'pending' => 'clock',
                'deactivated' => 'x-circle',
                default => 'question',
            },
        ];
    }

    /**
     * Generate random secure password
     */
    public static function generateSecurePassword(int $length = 12): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $all = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Validate password strength
     */
    public static function validatePasswordStrength(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if cashier can be assigned to shift
     */
    public static function canAssignToShift(Cashier $cashier, string $shiftDate): bool
    {
        if (!$cashier->isActive()) {
            return false;
        }

        // Check if cashier already has a shift on this date
        return !$cashier->shifts()
            ->whereDate('shift_date', $shiftDate)
            ->whereIn('status', ['not_started', 'in_progress'])
            ->exists();
    }

    /**
     * Get cashier performance metrics
     */
    public static function getPerformanceMetrics(Cashier $cashier, ?string $period = 'month'): array
    {
        $startDate = match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $shifts = $cashier->shifts()
            ->where('status', 'completed')
            ->where('shift_date', '>=', $startDate)
            ->get();

        return [
            'total_shifts' => $shifts->count(),
            'total_sales' => $shifts->sum('total_sales'),
            'average_sales' => $shifts->avg('total_sales'),
            'total_variance' => $shifts->sum('variance'),
            'variance_rate' => $shifts->count() > 0
                ? ($shifts->where('variance', '!=', 0)->count() / $shifts->count()) * 100
                : 0,
            'on_time_rate' => $shifts->count() > 0
                ? ($shifts->whereNotNull('actual_start_time')->count() / $shifts->count()) * 100
                : 0,
        ];
    }
}
