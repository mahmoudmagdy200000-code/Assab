<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\ApiResponse as ApiResponseTrait;
use App\Services\FirstLoginActivationResolver;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Modules\BrandOwner\Http\Requests\FirstLoginRequest;
use Modules\BrandOwner\Http\Requests\ForgotPasswordRequest;
use Modules\BrandOwner\Http\Requests\LoginRequest;
use Modules\BrandOwner\Http\Requests\ResetPasswordFirstLoginRequest;
use Modules\BrandOwner\Http\Requests\ResetPasswordRequest;
use Modules\BrandOwner\Http\Requests\VerifyOtpRequest;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\AuthService;

class AuthController extends Controller
{
    use ApiResponseTrait;

    protected AuthService $authService;

    private readonly FirstLoginActivationResolver $activation;

    public function __construct(AuthService $authService, FirstLoginActivationResolver $activation)
    {
        $this->authService = $authService;
        $this->activation = $activation;
    }

    public function firstLogin(FirstLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->firstLogin(
                $request->input('identifier'),
                $request->input('password')
            );

            $owner = $result['owner'];

            return $this->successResponse(
                'First login successful',
                [
                    'user' => $this->userPayload($owner),
                    'token' => $result['token'],
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
            // Bearer token (original contract), body `token`, or the default
            // password again — the «activate Account» screen exists in builds
            // that send each shape (see FirstLoginActivationResolver).
            /** @var BrandOwner $owner */
            $owner = $this->activation->resolve(
                BrandOwner::class,
                $request->user('sanctum'),
                $request->only(['token', 'identifier', 'default_password']),
            );

            if (! $owner->isFirstLogin()) {
                return $this->errorResponse('Account is already activated. Please use regular login.', 400);
            }

            $this->authService->resetPasswordFirstLogin($owner, $request->input('password'));

            return $this->successResponse('Password reset successfully. Please login with your new password.');
        } catch (AuthenticationException) {
            return $this->errorResponse('Sign in again to activate the account, or send the default password with the request.', 401);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->input('identifier'),
                $request->input('password')
            );

            $owner = $result['owner'];

            return $this->successResponse(
                'Login successful',
                [
                    'user' => $this->userPayload($owner),
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

    public function logout(): JsonResponse
    {
        try {
            $owner = auth('sanctum')->user();
            $this->authService->logout($owner);

            return $this->successResponse('Logout successful');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function me(): JsonResponse
    {
        try {
            $owner = auth('sanctum')->user();

            if (! $owner) {
                return $this->errorResponse('Not authenticated', 401);
            }

            return $this->successResponse('User retrieved successfully', [
                'user' => $this->userPayload($owner),
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    private function userPayload($owner): array
    {
        return [
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'image' => $owner->image_url,
            'created_at' => $owner->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
