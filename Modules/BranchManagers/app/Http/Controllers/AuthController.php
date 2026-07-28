<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\ApiResponse as ApiResponseTrait;
use App\Services\FirstLoginActivationResolver;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\BranchManagers\Http\Requests\FirstLoginRequest;
use Modules\BranchManagers\Http\Requests\ForgotPasswordRequest;
use Modules\BranchManagers\Http\Requests\LoginRequest;
use Modules\BranchManagers\Http\Requests\ResetPasswordFirstLoginRequest;
use Modules\BranchManagers\Http\Requests\ResetPasswordRequest;
use Modules\BranchManagers\Http\Requests\VerifyOtpRequest;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\AuthService;
use Modules\Notification\Http\Concerns\RegistersDeviceTokens;

class AuthController extends Controller
{
    use ApiResponseTrait, RegistersDeviceTokens;

    protected $authService;

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
                    // Reflects FirstLoginPolicy: false (default) means the
                    // sign-in itself completed activation, so the app must NOT
                    // route to «activate Account»; true = forced flow on.
                    'requires_password_reset' => $result['requiresPasswordReset'] ?? false,
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
            $activation = $this->activation->resolveFromRequest(BranchManager::class, $request);
            /** @var BranchManager $manager */
            $manager = $activation->account;

            // Already activated: this is a change-password, so it takes the
            // CURRENT password — a bare token must not rotate the credential.
            if (! $activation->maySetPassword($manager->isFirstLogin())) {
                return $this->errorResponse('Account is already activated. Send the current password, or use the change-password endpoint.', 400);
            }

            $this->authService->resetPasswordFirstLogin($manager, $request->password);

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
                $request->identifier,
                $request->password
            );

            $manager = $result['manager'];

            // Optional `fcm_token` in the body registers this device for push.
            // Never fails the login.
            $this->registerLoginDevice($request, $manager);

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

            return $this->successResponse([
                'expires_at' => now()->addMinutes(10)->toDateTimeString(),
            ], 'OTP sent successfully');
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

            // Generate reset token
            $resetToken = $this->authService->generateResetToken($request->identifier);

            return $this->successResponse([
                'reset_token' => $resetToken,
            ], 'OTP verified successfully');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPassword(
                $request->identifier,
                $request->reset_token,
                $request->password
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

            return $this->successResponse(null, 'Logout successful');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function me(): JsonResponse
    {
        try {
            $manager = auth('sanctum')->user();

            if (! $manager) {
                return $this->unauthorizedResponse('Not authenticated');
            }

            return $this->successResponse([
                'user' => [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'email' => $manager->email,
                    'phone' => $manager->phone,
                    'image' => $manager->image_url,
                    'created_at' => $manager->created_at?->format('Y-m-d H:i:s'),
                ],
            ], 'User retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
