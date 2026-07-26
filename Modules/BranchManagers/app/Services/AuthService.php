<?php

namespace Modules\BranchManagers\Services;

use App\Exceptions\FirstLoginRequiredException;
use App\Services\FirstLoginPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\BranchManagers\Events\PasswordChangedEvent;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Models\BranchManagerOtp;
use Modules\Notification\Mail\NotificationMail;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;

class AuthService
{
    public function __construct(
        private SmsProviderInterface $smsProvider,
        private readonly FirstLoginPolicy $firstLoginPolicy,
    ) {}

    /**
     * Handle first login
     */
    public function firstLogin(string $identifier, string $password)
    {
        // Check if identifier is email or phone
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $manager = BranchManager::where($field, $identifier)->first();

        if (! $manager || ! Hash::check($password, $manager->password)) {
            throw new \Exception('Invalid credentials ');
        }

        if (! $manager->isActive()) {
            throw new \Exception('Account is inactive');
        }

        // Signing in with the issued password COMPLETES activation (decision
        // 2026-07-26): the mobile «activate Account» screen posts neither the
        // first-login token nor the default password, and `is_first_login` kept
        // the normal login closed — the account was locked out for good.
        // Idempotent, so a repeat call behaves like a normal login.
        $this->completeActivation($manager);

        $token = $manager->createToken('first-login-token')->plainTextToken;

        return [
            'manager' => $manager,
            'token' => $token,
        ];
    }

    /** Clear the first-login flag once the issued password has been proven. */
    private function completeActivation(BranchManager $manager): void
    {
        if ($manager->isFirstLogin()) {
            $manager->forceFill(['is_first_login' => false])->save();
        }
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

        // Provisioning marks the row is_first_login, so this fires on the very
        // first mobile login — exactly where the dashboard credential would
        // otherwise start diverging.
        PasswordChangedEvent::dispatch($manager);

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

        if (! $manager || ! Hash::check($password, $manager->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (! $manager->isActive()) {
            throw new \Exception('Account is inactive');
        }

        // A correct password here activates the account too, rather than
        // bouncing the user to a screen that cannot complete (2026-07-26).
        $this->completeActivation($manager);

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

        if (! $otpRecord) {
            throw new \Exception('Invalid or expired OTP');
        }

        if (! Hash::check($otp, $otpRecord->otp)) {
            throw new \Exception('Invalid OTP');
        }

        return $otpRecord;
    }

    /**
     * Generate reset token after OTP verification
     */
    public function generateResetToken(string $identifier): string
    {
        // Generate a random token
        $resetToken = bin2hex(random_bytes(32));

        // Store reset token in OTP table as a new record (hashed at rest)
        BranchManagerOtp::create([
            'identifier' => $identifier,
            'otp' => Hash::make($resetToken),
            'type' => 'reset_token',
            'expires_at' => Carbon::now()->addHours(1),
            'is_used' => false,
        ]);

        return $resetToken;
    }

    /**
     * Reset password using reset token
     */
    public function resetPassword(string $identifier, string $resetToken, string $newPassword)
    {
        // Verify reset token (hashed at rest; match by Hash::check)
        $otpRecord = BranchManagerOtp::where('identifier', $identifier)
            ->where('type', 'reset_token')
            ->where('expires_at', '>', Carbon::now())
            ->where('is_used', false)
            ->latest()
            ->get()
            ->first(fn ($record) => Hash::check($resetToken, $record->otp));

        if (! $otpRecord) {
            throw new \Exception('Invalid or expired reset token');
        }

        // Find manager
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $manager = BranchManager::where($field, $identifier)->first();

        if (! $manager) {
            throw new \Exception('Branch Manager not found');
        }

        // Update password
        $manager->update([
            'password' => Hash::make($newPassword),
        ]);

        PasswordChangedEvent::dispatch($manager);

        // Revoke all existing sessions after a password reset
        $manager->tokens()->delete();

        // Mark reset token as used
        $otpRecord->update(['is_used' => true]);

        // Delete all OTP records for this identifier
        BranchManagerOtp::where('identifier', $identifier)->delete();

        return $manager;
    }

    /**
     * Send OTP by Email
     */
    private function sendOtpByEmail(string $email, string $otp)
    {
        Mail::to($email)->send(new NotificationMail(
            'Password Reset Code',
            "Your password reset code is: {$otp}",
        ));
    }

    /**
     * Send OTP by SMS
     */
    private function sendOtpBySms(string $phone, string $otp)
    {
        $this->smsProvider->send($phone, "Your password reset code is: {$otp}");
    }

    /**
     * Logout
     */
    public function logout(BranchManager $manager)
    {
        $manager->currentAccessToken()->delete();
    }
}
