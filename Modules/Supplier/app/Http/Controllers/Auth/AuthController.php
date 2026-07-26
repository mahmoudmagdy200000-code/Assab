<?php

namespace Modules\Supplier\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use App\Services\FirstLoginActivationResolver;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\Auth\ChangePasswordRequest;
use Modules\Supplier\Http\Requests\Auth\FirstLoginRequest;
use Modules\Supplier\Http\Requests\Auth\LoginRequest;
use Modules\Supplier\Http\Requests\Auth\ResetPasswordFirstLoginRequest;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Services\AuthService;

class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly FirstLoginActivationResolver $activation,
    ) {}

    /**
     * First login - when supplier receives default password from admin
     */
    public function firstLogin(FirstLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->firstLogin(
                $request->identifier,
                $request->password
            );

            $supplier = $result['supplier'];

            return $this->successResponse([
                'user' => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'image' => $supplier->image_url,
                    'created_at' => $supplier->created_at?->format('Y-m-d H:i:s'),
                ],
                'token' => $result['token'],
                'requires_password_reset' => true,
            ], 'First login successful. Please reset your password.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'first login');
        }
    }

    /**
     * Reset password on first login
     */
    public function resetPasswordFirstLogin(ResetPasswordFirstLoginRequest $request): JsonResponse
    {
        try {
            // Bearer token (original contract), body `token`, or the default
            // password again — see FirstLoginActivationResolver. The screen used
            // to answer a dead-end "Unauthenticated." to a build that sends the
            // last shape.
            /** @var Supplier $supplier */
            $supplier = $this->activation->resolveFromRequest(Supplier::class, $request);

            if (! $supplier->isActive()) {
                return $this->forbiddenResponse('Your account is inactive. Please contact administrator.');
            }
            if (! $supplier->isFirstLogin()) {
                return $this->errorResponse('Account is already activated. Please use regular login.', 400);
            }

            $this->authService->resetPasswordFirstLogin($supplier, $request->password);

            return $this->successResponse(null, 'Password reset successfully. Please login with your new password.');
        } catch (AuthenticationException) {
            return $this->unauthorizedResponse('Sign in again to activate the account, or send the default password with the request.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'resetting password');
        }
    }

    /**
     * Standard login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->identifier,
                $request->password,
                $request->boolean('remember_me', false)
            );

            $supplier = $result['supplier'];

            return $this->successResponse([
                'user' => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'image' => $supplier->image_url,
                    'created_at' => $supplier->created_at?->format('Y-m-d H:i:s'),
                ],
                'token' => $result['token'],
            ], 'Login successful');
        } catch (\Exception $e) {
            return $this->handleException($e, 'login');
        }
    }

    /**
     * Logout
     */
    public function logout(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();

            if ($supplier) {
                $this->authService->logout($supplier);
            }

            return $this->successResponse(null, 'Logout successful');
        } catch (\Exception $e) {
            return $this->handleException($e, 'logout');
        }
    }

    /**
     * Get authenticated supplier
     */
    public function me(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();

            if (! $supplier) {
                return $this->unauthorizedResponse('Not authenticated');
            }

            return $this->successResponse([
                'user' => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'image' => $supplier->image_url,
                    'created_at' => $supplier->created_at?->format('Y-m-d H:i:s'),
                ],
            ], 'User retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier');
        }
    }

    /**
     * Change password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();

            if (! $supplier) {
                return $this->unauthorizedResponse('Not authenticated');
            }

            $this->authService->changePassword(
                $supplier,
                $request->current_password,
                $request->new_password
            );

            return $this->successResponse(null, 'Password changed successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'changing password');
        }
    }
}
