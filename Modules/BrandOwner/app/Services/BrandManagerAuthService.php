<?php

namespace Modules\BrandOwner\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\BrandOwner\Models\BrandManager;
use Modules\BrandOwner\Models\BrandManagerOtp;
use Modules\Notification\Services\OtpDeliveryService;

class BrandManagerAuthService
{
    public function __construct(private readonly OtpDeliveryService $otpDelivery) {}

    public function login(string $identifier, string $password): array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $manager = BrandManager::where($field, $identifier)->first();

        if (! $manager || ! Hash::check($password, $manager->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (! $manager->isActive()) {
            throw new \Exception('Account is inactive');
        }

        $token = $manager->createToken('brand-manager-token')->plainTextToken;

        return [
            'manager' => $manager,
            'token' => $token,
        ];
    }

    public function sendOtp(string $identifier, string $type): BrandManagerOtp
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        BrandManagerOtp::where('identifier', $identifier)->delete();

        $otpRecord = BrandManagerOtp::create([
            'identifier' => $identifier,
            'otp' => Hash::make($otp),
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes(10),
            'is_used' => false,
        ]);

        if ($type === 'email') {
            $this->sendOtpByEmail($identifier, $otp);
        } else {
            $this->sendOtpBySms($identifier, $otp);
        }

        return $otpRecord;
    }

    public function verifyOtp(string $identifier, string $otp): BrandManagerOtp
    {
        $otpRecord = BrandManagerOtp::where('identifier', $identifier)
            ->valid()
            ->latest()
            ->first();

        if (! $otpRecord) {
            throw new \Exception('Invalid or expired OTP');
        }

        if (! Hash::check($otp, $otpRecord->otp)) {
            throw new \Exception('Invalid OTP');
        }

        return $otpRecord;
    }

    public function generateResetToken(string $identifier): string
    {
        $resetToken = bin2hex(random_bytes(32));

        BrandManagerOtp::create([
            'identifier' => $identifier,
            'otp' => $resetToken,
            'type' => 'reset_token',
            'expires_at' => Carbon::now()->addHour(),
            'is_used' => false,
        ]);

        return $resetToken;
    }

    public function resetPassword(string $identifier, string $resetToken, string $newPassword): BrandManager
    {
        $otpRecord = BrandManagerOtp::where('identifier', $identifier)
            ->where('type', 'reset_token')
            ->where('otp', $resetToken)
            ->where('expires_at', '>', Carbon::now())
            ->where('is_used', false)
            ->first();

        if (! $otpRecord) {
            throw new \Exception('Invalid or expired reset token');
        }

        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $manager = BrandManager::where($field, $identifier)->first();

        if (! $manager) {
            throw new \Exception('Brand Manager not found');
        }

        $manager->update([
            'password' => Hash::make($newPassword),
        ]);

        $otpRecord->update(['is_used' => true]);

        BrandManagerOtp::where('identifier', $identifier)->delete();

        return $manager;
    }

    public function logout(BrandManager $manager): void
    {
        $manager->currentAccessToken()?->delete();
    }

    private function sendOtpByEmail(string $email, string $otp): void
    {
        $this->otpDelivery->sendEmail($email, $otp);
    }

    private function sendOtpBySms(string $phone, string $otp): void
    {
        $this->otpDelivery->sendSms($phone, $otp);
    }
}
