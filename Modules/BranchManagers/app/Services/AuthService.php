<?php

namespace Modules\BranchManagers\Services;

use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Models\BranchManagerOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AuthService
{
    /**
     * Handle first login
     */
    public function firstLogin(string $email, string $password)
    {
        $manager = BranchManager::where('email', $email)->first();

        if (!$manager || !Hash::check($password, $manager->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (!$manager->isActive()) {
            throw new \Exception('Account is inactive');
        }

        if (!$manager->isFirstLogin()) {
            throw new \Exception('Please use regular login');
        }

        $token = $manager->createToken('first-login-token')->plainTextToken;
        return [
            'manager' => $manager,
            'token' => $token,
        ];
    }

    /**
     * Reset password on first login
     */
    public function resetPasswordFirstLogin(BranchManager $manager, string $newPassword)
    {
        $manager->update([
            'password' => Hash::make($newPassword),
            'is_first_login' => false,
        ]);

        return $manager;
    }

    /**
     * Handle regular login
     */
    public function login(string $identifier, string $password)
    {
        // Check if identifier is email or phone
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $manager = BranchManager::where($field, $identifier)->first();

        if (!$manager || !Hash::check($password, $manager->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (!$manager->isActive()) {
            throw new \Exception('Account is inactive');
        }

        if ($manager->isFirstLogin()) {
            throw new \Exception('Please complete first login setup');
        }

        // Create token for API authentication
        $token = $manager->createToken('branch-manager-token')->plainTextToken;

        return [
            'manager' => $manager,
            'token' => $token,
        ];
    }

    /**
     * Send OTP for password reset
     */
    public function sendOtp(string $identifier, string $type)
    {
        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Delete old OTPs for this identifier
        BranchManagerOtp::where('identifier', $identifier)->delete();

        // Create new OTP
        $otpRecord = BranchManagerOtp::create([
            'identifier' => $identifier,
            'otp' => Hash::make($otp),
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes(10),
            'is_used' => false,
        ]);

        // Send OTP based on type
        if ($type === 'email') {
            $this->sendOtpByEmail($identifier, $otp);
        } else {
            $this->sendOtpBySms($identifier, $otp);
        }

        return $otpRecord;
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(string $identifier, string $otp)
    {
        $otpRecord = BranchManagerOtp::where('identifier', $identifier)
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
     * Reset password using OTP
     */
    public function resetPassword(string $identifier, string $otp, string $newPassword)
    {
        // Verify OTP
        $otpRecord = $this->verifyOtp($identifier, $otp);

        // Find manager
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $manager = BranchManager::where($field, $identifier)->first();

        if (!$manager) {
            throw new \Exception('Branch Manager not found');
        }

        // Update password
        $manager->update([
            'password' => Hash::make($newPassword),
        ]);

        // Mark OTP as used
        $otpRecord->markAsUsed();

        return $manager;
    }

    /**
     * Send OTP by Email
     */
    private function sendOtpByEmail(string $email, string $otp)
    {
        // TODO: Implement email sending
        // Mail::to($email)->send(new OtpMail($otp));

        // For development, you can log the OTP
        Log::info("OTP for $email: $otp");
    }

    /**
     * Send OTP by SMS
     */
    private function sendOtpBySms(string $phone, string $otp)
    {
        // TODO: Implement SMS sending using a service like Twilio
        // Example: Twilio::message($phone, "Your OTP is: $otp");

        // For development, you can log the OTP
        Log::info("OTP for $phone: $otp");
    }

    /**
     * Logout
     */
    public function logout(BranchManager $manager)
    {
        $manager->currentAccessToken()->delete();
    }
}
