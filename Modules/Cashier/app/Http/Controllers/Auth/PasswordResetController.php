<?php

namespace Modules\Cashier\Http\Controllers\Auth;


use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Services\OTPService;
use Modules\Cashier\Http\Requests\Auth\SendOTPRequest;
use Modules\Cashier\Http\Requests\Auth\VerifyOTPRequest;
use Modules\Cashier\Http\Requests\Auth\ResetPasswordRequest;
use Modules\Cashier\Models\Cashier;

class PasswordResetController extends Controller
{
    public function __construct(
        private OTPService $otpService
    ) {}

    /**
     * Send OTP for password reset
     */
    public function sendOTP(SendOTPRequest $request): JsonResponse
    {
        $cashier = Cashier::where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if (!$cashier) {
            return response()->error('Cashier not found', 404);
        }

        $otp = $this->otpService->generateOTP(
            identifier: $request->identifier,
            type: $request->type
        );

        // Send OTP via email or SMS
        if ($request->type === 'email') {
            $cashier->notify(new \Modules\Cashier\Notifications\PasswordResetOTPNotification($otp));
        } else {
            // Send SMS
            // Implement SMS service
        }

        return response()->success([
            'expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ], 'OTP sent successfully');
    }

    /**
     * Verify OTP
     */
    public function verifyOTP(VerifyOTPRequest $request): JsonResponse
    {
        $isValid = $this->otpService->verifyOTP(
            identifier: $request->identifier,
            otp: $request->otp
        );

        if (!$isValid) {
            return response()->error('Invalid or expired OTP', 400);
        }

        // Generate reset token
        $resetToken = $this->otpService->generateResetToken($request->identifier);

        return response()->success([
            'reset_token' => $resetToken,
        ], 'OTP verified successfully');
    }

    /**
     * Reset password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        // Verify reset token
        if (!$this->otpService->verifyResetToken($request->identifier, $request->reset_token)) {
            return response()->error('Invalid or expired reset token', 400);
        }

        $cashier = Cashier::where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if (!$cashier) {
            return response()->error('Cashier not found', 404);
        }

        // Update password
        $cashier->update([
            'password' => Hash::make($request->password),
        ]);

        // Invalidate reset token
        $this->otpService->invalidateResetToken($request->identifier);

        return response()->success(null, 'Password reset successfully. You can now login with your new password.');
    }
}
