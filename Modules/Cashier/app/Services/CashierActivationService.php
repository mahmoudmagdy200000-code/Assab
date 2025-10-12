<?php

namespace Modules\Cashier\Services;

use Modules\Cashier\Models\Cashier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Cashier\Notifications\CashierActivationNotification;

class CashierActivationService
{
    /**
     * Send activation link to cashier
     */
    public function sendActivationLink(Cashier $cashier, string $defaultPassword): void
    {
        // Generate activation token
        $token = Str::random(64);

        // Store token in cache for 24 hours
        Cache::put(
            "cashier_activation_{$cashier->email}",
            $token,
            now()->addHours(24)
        );

        // Send notification with activation link
        $cashier->notify(new CashierActivationNotification($token, $defaultPassword));
    }

    /**
     * Verify activation token
     */
    public function verifyActivationToken(string $email, string $token): bool
    {
        $cachedToken = Cache::get("cashier_activation_{$email}");

        return $cachedToken === $token;
    }

    /**
     * Invalidate activation token
     */
    public function invalidateActivationToken(string $email): void
    {
        Cache::forget("cashier_activation_{$email}");
    }
}
