<?php

namespace Modules\Supplier\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\Auth\ResetPasswordRequest;
use Modules\Supplier\Http\Requests\Auth\SendOTPRequest;
use Modules\Supplier\Http\Requests\Auth\VerifyOTPRequest;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Services\AuthService;
use Modules\Supplier\Services\OTPService;

class PasswordResetController extends BaseController
{
    public function __construct(
        private readonly OTPService $otpService,
        private readonly AuthService $authService,
    ) {}

    /**
     * Send OTP for password reset
     */
    public function sendOTP(SendOTPRequest $request): JsonResponse
    {
        try {
            $supplier = Supplier::where('email', $request->identifier)
                ->orWhere('phone', $request->identifier)
                ->first();

            if (! $supplier) {
                return $this->notFoundResponse('Supplier not found');
            }

            $this->otpService->generateOTP(
                identifier: $request->identifier,
                type: $request->type
            );

            return $this->successResponse([
                'expires_at' => now()->addMinutes(10)->toDateTimeString(),
            ], 'OTP sent successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'sending OTP');
        }
    }

    /**
     * Verify OTP
     */
    public function verifyOTP(VerifyOTPRequest $request): JsonResponse
    {
        try {
            $isValid = $this->otpService->verifyOTP(
                identifier: $request->identifier,
                otp: $request->otp
            );

            if (! $isValid) {
                return $this->errorResponse('Invalid or expired OTP', 400);
            }

            // Generate reset token
            $resetToken = $this->otpService->generateResetToken($request->identifier);

            return $this->successResponse([
                'reset_token' => $resetToken,
            ], 'OTP verified successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'verifying OTP');
        }
    }

    /**
     * Reset password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            // Verify reset token
            if (! $this->otpService->verifyResetToken($request->identifier, $request->reset_token)) {
                return $this->errorResponse('Invalid or expired reset token', 400);
            }

            $supplier = Supplier::where('email', $request->identifier)
                ->orWhere('phone', $request->identifier)
                ->first();

            if (! $supplier) {
                return $this->notFoundResponse('Supplier not found');
            }

            $this->authService->resetPasswordByOtp($supplier, $request->password);

            // Invalidate reset token
            $this->otpService->invalidateResetToken($request->identifier);

            return $this->successResponse(null, 'Password reset successfully. You can now login with your new password.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'resetting password');
        }
    }
}
