<?php

namespace Modules\Supplier\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Mail\NotificationMail;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierOtp;

class OTPService
{
    public function __construct(
        private SmsProviderInterface $smsProvider
    ) {}

    /**
     * Generate and send OTP
     */
    public function generateOTP(string $identifier, string $type, int $expiryMinutes = 10): string
    {
        // Verify supplier exists
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $supplier = Supplier::where($field, $identifier)->first();

        if (! $supplier) {
            throw new \Exception('Supplier not found');
        }

        // Generate OTP
        $otpRecord = SupplierOtp::generate($identifier, $type, $expiryMinutes);

        // Get plain OTP (before hashing) - we need to return it for sending
        // Note: The generate method hashes it, so we need to generate it separately
        $plainOtp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Delete old OTPs
        SupplierOtp::where('identifier', $identifier)
            ->where('type', $type)
            ->delete();

        // Create new OTP record
        SupplierOtp::create([
            'identifier' => $identifier,
            'otp' => Hash::make($plainOtp),
            'type' => $type,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'is_used' => false,
        ]);

        // Send OTP via email or SMS
        if ($type === 'email') {
            $this->sendOtpByEmail($identifier, $plainOtp);
        } else {
            $this->sendOtpBySms($identifier, $plainOtp);
        }

        return $plainOtp;
    }

    /**
     * Verify OTP
     */
    public function verifyOTP(string $identifier, string $otp): bool
    {
        $otpRecord = SupplierOtp::where('identifier', $identifier)
            ->valid()
            ->latest()
            ->first();

        if (! $otpRecord) {
            return false;
        }

        return Hash::check($otp, $otpRecord->otp);
    }

    /**
     * Generate reset token after OTP verification
     */
    public function generateResetToken(string $identifier): string
    {
        $token = bin2hex(random_bytes(32));

        // Store token in cache for 10 minutes
        cache()->put("supplier_reset_token_{$identifier}", $token, now()->addMinutes(10));

        return $token;
    }

    /**
     * Verify reset token
     */
    public function verifyResetToken(string $identifier, string $token): bool
    {
        $storedToken = cache()->get("supplier_reset_token_{$identifier}");

        return $storedToken && hash_equals($storedToken, $token);
    }

    /**
     * Invalidate reset token
     */
    public function invalidateResetToken(string $identifier): void
    {
        cache()->forget("supplier_reset_token_{$identifier}");
    }

    /**
     * Send OTP by Email
     */
    private function sendOtpByEmail(string $email, string $otp): void
    {
        Mail::to($email)->send(new NotificationMail(
            'Password Reset Code',
            "Your password reset code is: {$otp}",
        ));
    }

    /**
     * Send OTP by SMS
     */
    private function sendOtpBySms(string $phone, string $otp): void
    {
        $this->smsProvider->send($phone, "Your password reset code is: {$otp}");
    }
}
