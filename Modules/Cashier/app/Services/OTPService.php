<?php

namespace Modules\Cashier\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OTPService
{
    private const OTP_LENGTH = 6;
    private const OTP_EXPIRY_MINUTES = 10;
    private const MAX_ATTEMPTS = 3;

    /**
     * Generate OTP
     */
    public function generateOTP(string $identifier, string $type): string
    {
        $otp = str_pad(random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);

        $key = "otp_{$type}_{$identifier}";

        Cache::put($key, [
            'otp' => $otp,
            'attempts' => 0,
            'created_at' => now(),
        ], now()->addMinutes(self::OTP_EXPIRY_MINUTES));

        return $otp;
    }

    /**
     * Verify OTP
     */
    public function verifyOTP(string $identifier, string $otp, string $type = 'email'): bool
    {
        $key = "otp_{$type}_{$identifier}";
        $data = Cache::get($key);

        if (!$data) {
            return false;
        }

        // Check attempts
        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return false;
        }

        // Increment attempts
        $data['attempts']++;
        Cache::put($key, $data, now()->addMinutes(self::OTP_EXPIRY_MINUTES));

        // Verify OTP
        if ($data['otp'] === $otp) {
            // Mark as verified
            $this->markOTPAsVerified($identifier, $type);
            return true;
        }

        return false;
    }

    /**
     * Mark OTP as verified
     */
    private function markOTPAsVerified(string $identifier, string $type): void
    {
        $key = "otp_verified_{$type}_{$identifier}";
        Cache::put($key, true, now()->addMinutes(30));
    }

    /**
     * Check if OTP is verified
     */
    public function isOTPVerified(string $identifier, string $type = 'email'): bool
    {
        $key = "otp_verified_{$type}_{$identifier}";
        return Cache::get($key, false);
    }

    /**
     * Generate reset token
     */
    public function generateResetToken(string $identifier): string
    {
        $token = Str::random(64);

        Cache::put(
            "reset_token_{$identifier}",
            $token,
            now()->addHours(1)
        );

        return $token;
    }

    /**
     * Verify reset token
     */
    public function verifyResetToken(string $identifier, string $token): bool
    {
        $cachedToken = Cache::get("reset_token_{$identifier}");
        return $cachedToken === $token;
    }

    /**
     * Invalidate reset token
     */
    public function invalidateResetToken(string $identifier): void
    {
        Cache::forget("reset_token_{$identifier}");
        Cache::forget("otp_verified_email_{$identifier}");
        Cache::forget("otp_verified_phone_{$identifier}");
    }
}
