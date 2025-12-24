<?php

namespace Modules\BranchManagers\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\BranchManagers\Http\Requests\{
    FirstLoginRequest,
    ResetPasswordFirstLoginRequest,
    LoginRequest,
    ForgotPasswordRequest,
    VerifyOtpRequest,
    ResetPasswordRequest
};
use Modules\BranchManagers\Services\AuthService;
use Modules\BranchManagers\Transformers\BranchManagerResource;
use Illuminate\Http\JsonResponse;
use App\ApiResponse as ApiResponseTrait;

class AuthController extends Controller
{
    use ApiResponseTrait;

    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function firstLogin(FirstLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->firstLogin(
                $request->identifier,
                $request->password
            );

            $manager = $result['manager'];
            $token = $result['token'];

            return $this->successResponse(
                __('auth.first_login_success'),
                [
                    'user' => [
                        'id' => $manager->id,
                        'name' => $manager->name,
                        'email' => $manager->email,
                        'phone' => $manager->phone,
                        'image' => $manager->image_url,
                        'created_at' => $manager->created_at?->format('Y-m-d H:i:s'),
                    ],
                    'token' => $token,
                    'requires_password_reset' => true,
                ]
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 401);
        }
    }

    public function resetPasswordFirstLogin(ResetPasswordFirstLoginRequest $request): JsonResponse
    {
        try {
            $manager = auth('sanctum')->user();

            if (!$manager || !$manager->isFirstLogin()) {
                return $this->errorResponse('Invalid request', 400);
            }

            $this->authService->resetPasswordFirstLogin($manager, $request->password);

            return $this->successResponse('Password reset successfully. Please login with your new password.');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->identifier,
                $request->password
            );

            $manager = $result['manager'];

            return $this->successResponse(
                'Login successful',
                [
                    'user' => [
                        'id' => $manager->id,
                        'name' => $manager->name,
                        'email' => $manager->email,
                        'phone' => $manager->phone,
                        'image' => $manager->image_url,
                        'created_at' => $manager->created_at?->format('Y-m-d H:i:s'),
                    ],
                    'token' => $result['token'],
                ]
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 401);
        }
    }


    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->sendOtp(
                $request->identifier,
                $request->type
            );

            return $this->successResponse('OTP sent successfully');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $this->authService->verifyOtp(
                $request->identifier,
                $request->otp
            );

            return $this->successResponse('OTP verified successfully');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPassword(
                $request->identifier,
                // $request->otp,
                $request->password
            );

            return $this->successResponse('Password reset successfully');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function logout(): JsonResponse
    {
        try {
            $manager = auth('sanctum')->user();
            $this->authService->logout($manager);

            return $this->successResponse('Logout successful');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function me(): JsonResponse
    {
        return $this->successResponse('Authenticated user', new BranchManagerResource(auth('sanctum')->user()));
    }
}
