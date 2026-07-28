<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\ApiResponse as ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\BrandOwner\Http\Requests\ForgotPasswordRequest;
use Modules\BrandOwner\Http\Requests\LoginRequest;
use Modules\BrandOwner\Http\Requests\ResetPasswordRequest;
use Modules\BrandOwner\Http\Requests\VerifyOtpRequest;
use Modules\BrandOwner\Services\BrandManagerAuthService;
use Modules\Notification\Http\Concerns\RegistersDeviceTokens;

class BrandManagerAuthController extends Controller
{
    use ApiResponseTrait, RegistersDeviceTokens;

    public function __construct(protected BrandManagerAuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->input('identifier'),
                $request->input('password')
            );

            // Optional `fcm_token` in the body registers this device for push.
            // Never fails the login.
            $this->registerLoginDevice($request, $result['manager']);

            return $this->successResponse('Login successful', [
                'user' => $this->userPayload($result['manager']),
                'token' => $result['token'],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, 401);
        }
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->sendOtp(
                $request->input('identifier'),
                $request->input('type')
            );

            return $this->successResponse('OTP sent successfully', [
                'expires_at' => now()->addMinutes(10)->toDateTimeString(),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $this->authService->verifyOtp(
                $request->input('identifier'),
                $request->input('otp')
            );

            $resetToken = $this->authService->generateResetToken($request->input('identifier'));

            return $this->successResponse('OTP verified successfully', [
                'reset_token' => $resetToken,
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPassword(
                $request->input('identifier'),
                $request->input('reset_token'),
                $request->input('password')
            );

            return $this->successResponse('Password reset successfully. You can now login with your new password.');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $manager = auth('sanctum')->user();

            // Release the handset before the access token dies, or the client
            // can no longer reach the device-token endpoint to clean up.
            $this->revokeLoginDevice($request, $manager);

            $this->authService->logout($manager);

            return $this->successResponse('Logout successful');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function me(): JsonResponse
    {
        try {
            $manager = auth('sanctum')->user();

            if (! $manager) {
                return $this->errorResponse('Not authenticated', 401);
            }

            return $this->successResponse('User retrieved successfully', [
                'user' => $this->userPayload($manager),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    private function userPayload($manager): array
    {
        return [
            'id' => $manager->id,
            'name' => $manager->name,
            'email' => $manager->email,
            'phone' => $manager->phone,
            'image' => $manager->image_url,
            'role' => 'brand_manager',
            'created_at' => $manager->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
