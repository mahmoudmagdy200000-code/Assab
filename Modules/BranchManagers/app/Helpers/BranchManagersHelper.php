<?php

namespace Modules\BranchManagers\Helpers;

use Modules\BranchManagers\Models\BranchManager;

class BranchManagerHelper
{
    /**
     * Get manager by identifier (email or phone)
     */
    public static function findByIdentifier(string $identifier): ?BranchManager
    {
        return BranchManager::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();
    }

    /**
     * Format manager status badge
     */
    public static function getStatusBadge(string $status, bool $isActive): array
    {
        if (!$isActive) {
            return [
                'text' => 'Inactive',
                'color' => 'gray',
                'icon' => 'x-circle',
            ];
        }

        return [
            'text' => match($status) {
                'active' => 'Active',
                'pending' => 'Pending',
                'suspended' => 'Suspended',
                default => 'Unknown',
            },
            'color' => match($status) {
                'active' => 'green',
                'pending' => 'yellow',
                'suspended' => 'red',
                default => 'gray',
            },
            'icon' => match($status) {
                'active' => 'check-circle',
                'pending' => 'clock',
                'suspended' => 'ban',
                default => 'question',
            },
        ];
    }

    /**
     * Generate secure random password
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
            'strength' => self::calculatePasswordStrength($password),
        ];
    }

    /**
     * Calculate password strength score
     */
    private static function calculatePasswordStrength(string $password): string
    {
        $score = 0;

        if (strlen($password) >= 8) $score++;
        if (strlen($password) >= 12) $score++;
        if (preg_match('/[A-Z]/', $password)) $score++;
        if (preg_match('/[a-z]/', $password)) $score++;
        if (preg_match('/[0-9]/', $password)) $score++;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;

        return match(true) {
            $score <= 2 => 'weak',
            $score <= 4 => 'medium',
            default => 'strong',
        };
    }

    /**
     * Check if manager has permission
     */
    public static function hasPermission(BranchManager $manager, string $permission): bool
    {
        // Implement role-based permissions if needed
        // For now, all active managers have full access
        return $manager->isActive();
    }

    /**
     * Get manager dashboard summary
     */
    public static function getDashboardSummary(BranchManager $manager): array
    {
        return [
            'total_cashiers' => $manager->getTotalCashiers(),
            'active_cashiers' => $manager->getActiveCashiers(),
            'today_shifts' => $manager->getTodayShifts(),
            'total_expenses' => $manager->getTotalExpenses(),
        ];
    }

    /**
     * Format time period for stats
     */
    public static function getTimePeriodDates(string $period): array
    {
        return match($period) {
            'today' => [
                'start' => now()->startOfDay(),
                'end' => now()->endOfDay(),
            ],
            'yesterday' => [
                'start' => now()->subDay()->startOfDay(),
                'end' => now()->subDay()->endOfDay(),
            ],
            'week' => [
                'start' => now()->startOfWeek(),
                'end' => now()->endOfWeek(),
            ],
            'month' => [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ],
            'year' => [
                'start' => now()->startOfYear(),
                'end' => now()->endOfYear(),
            ],
            default => [
                'start' => now()->startOfDay(),
                'end' => now()->endOfDay(),
            ],
        };
    }
}
