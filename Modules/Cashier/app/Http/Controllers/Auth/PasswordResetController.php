<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Http\Requests\Auth\ResetPasswordRequest;
use Modules\Cashier\Http\Requests\Auth\SendOTPRequest;
use Modules\Cashier\Http\Requests\Auth\VerifyOTPRequest;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\OTPService;

class PasswordResetController extends BaseController
{
    public function __construct(
        private OTPService $otpService
    ) {}

    /**
     * Send OTP for password reset (3.2.1.2) – Email or Phone
     */
    public function sendOTP(SendOTPRequest $request): JsonResponse
    {
        $cashier = Cashier::where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if (! $cashier) {
            return $this->errorResponse('Cashier not found.', 404);
        }

        if (! $cashier->isActive()) {
            return $this->errorResponse('Your account is not active. Please contact your manager.', 403);
        }

        $sent = $this->otpService->sendOTP(
            identifier: $request->identifier,
            type: $request->type
        );

        if (! $sent) {
            return $this->errorResponse('Could not deliver the verification code. Please try again.', 503);
        }

        return $this->successResponse([
            'expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ], 'OTP sent successfully');
    }

    /**
     * Verify OTP and return reset token (3.2.1.2)
     */
    public function verifyOTP(VerifyOTPRequest $request): JsonResponse
    {
        $type = filter_var($request->identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $isValid = $this->otpService->verifyOTP(
            identifier: $request->identifier,
            otp: $request->otp,
            type: $type
        );

        if (! $isValid) {
            return $this->errorResponse('Invalid or expired OTP.', 400);
        }

        $resetToken = $this->otpService->generateResetToken($request->identifier);

        return $this->successResponse([
            'reset_token' => $resetToken,
        ], 'OTP verified successfully');
    }

    /**
     * Set new password after OTP verification (3.2.1.2)
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if (! $this->otpService->verifyResetToken($request->identifier, $request->reset_token)) {
            return $this->errorResponse('Invalid or expired reset token.', 400);
        }

        $cashier = Cashier::where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if (! $cashier) {
            return $this->errorResponse('Cashier not found.', 404);
        }

        $cashier->update([
            'password' => Hash::make($request->password),
        ]);

        $this->otpService->invalidateResetToken($request->identifier);

        return $this->successResponse(null, 'Password reset successfully. You can now log in with your new password.');
    }
}
