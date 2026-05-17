<?php

namespace Modules\BrandOwner\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\BrandOwnerOtp;

class AuthService
{
    /**
     * Handle first login.
     */
    public function firstLogin(string $identifier, string $password): array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $owner = BrandOwner::where($field, $identifier)->first();

        if (!$owner || !Hash::check($password, $owner->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (!$owner->isActive()) {
            throw new \Exception('Account is inactive');
        }

        if (!$owner->isFirstLogin()) {
            throw new \Exception('Account is already activated. Please use regular login.');
        }

        $token = $owner->createToken('first-login-token')->plainTextToken;

        return [
            'owner' => $owner,
            'token' => $token,
        ];
    }

    /**
     * Reset password on first login.
     */
    public function resetPasswordFirstLogin(BrandOwner $owner, string $newPassword): BrandOwner
    {
        $owner->update([
            'password'       => Hash::make($newPassword),
            'is_first_login' => false,
        ]);

        return $owner;
    }

    /**
     * Handle regular login.
     */
    public function login(string $identifier, string $password): array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $owner = BrandOwner::where($field, $identifier)->first();

        if (!$owner || !Hash::check($password, $owner->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (!$owner->isActive()) {
            throw new \Exception('Account is inactive');
        }

        if ($owner->isFirstLogin()) {
            throw new \Exception('Please complete first login setup');
        }

        $token = $owner->createToken('brand-owner-token')->plainTextToken;

        return [
            'owner' => $owner,
            'token' => $token,
        ];
    }

    /**
     * Send OTP for password reset.
     */
    public function sendOtp(string $identifier, string $type): BrandOwnerOtp
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        BrandOwnerOtp::where('identifier', $identifier)->delete();

        $otpRecord = BrandOwnerOtp::create([
            'identifier' => $identifier,
            'otp'        => Hash::make($otp),
            'type'       => $type,
            'expires_at' => Carbon::now()->addMinutes(10),
            'is_used'    => false,
        ]);

        if ($type === 'email') {
            $this->sendOtpByEmail($identifier, $otp);
        } else {
            $this->sendOtpBySms($identifier, $otp);
        }

        return $otpRecord;
    }

    /**
     * Verify OTP.
     */
    public function verifyOtp(string $identifier, string $otp): BrandOwnerOtp
    {
        $otpRecord = BrandOwnerOtp::where('identifier', $identifier)
            ->valid()
            ->latest()
            ->first();

        if (!$otpRecord) {
            throw new \Exception('Invalid or expired OTP');
        }

        if (!Hash::check($otp, $otpRecord->otp)) {
            throw new \Exception('Invalid OTP');
        }

        return $otpRecord;
    }

    /**
     * Generate reset token after OTP verification.
     */
    public function generateResetToken(string $identifier): string
    {
        $resetToken = bin2hex(random_bytes(32));

        BrandOwnerOtp::create([
            'identifier' => $identifier,
            'otp'        => $resetToken,
            'type'       => 'reset_token',
            'expires_at' => Carbon::now()->addHour(),
            'is_used'    => false,
        ]);

        return $resetToken;
    }

    /**
     * Reset password using reset token.
     */
    public function resetPassword(string $identifier, string $resetToken, string $newPassword): BrandOwner
    {
        $otpRecord = BrandOwnerOtp::where('identifier', $identifier)
            ->where('type', 'reset_token')
            ->where('otp', $resetToken)
            ->where('expires_at', '>', Carbon::now())
            ->where('is_used', false)
            ->first();

        if (!$otpRecord) {
            throw new \Exception('Invalid or expired reset token');
        }

        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $owner = BrandOwner::where($field, $identifier)->first();

        if (!$owner) {
            throw new \Exception('Brand Owner not found');
        }

        $owner->update([
            'password' => Hash::make($newPassword),
        ]);

        $otpRecord->update(['is_used' => true]);

        BrandOwnerOtp::where('identifier', $identifier)->delete();

        return $owner;
    }

    /**
     * Logout.
     */
    public function logout(BrandOwner $owner): void
    {
        $owner->currentAccessToken()?->delete();
    }

    private function sendOtpByEmail(string $email, string $otp): void
    {
        // TODO: implement real mailer
        Log::info("OTP for $email: $otp");
    }

    private function sendOtpBySms(string $phone, string $otp): void
    {
        // TODO: implement SMS provider
        Log::info("OTP for $phone: $otp");
    }
}
