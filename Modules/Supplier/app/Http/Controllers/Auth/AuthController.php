<?php

namespace Modules\Supplier\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\Auth\FirstLoginRequest;
use Modules\Supplier\Http\Requests\Auth\ResetPasswordFirstLoginRequest;
use Modules\Supplier\Http\Requests\Auth\LoginRequest;
use Modules\Supplier\Services\AuthService;
use Modules\Supplier\Transformers\SupplierResource;

class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $authService
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

            return $this->successResponse([
                'supplier' => new SupplierResource($result['supplier']),
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
            $supplier = auth('supplier')->user();

            if (!$supplier || !$supplier->isFirstLogin()) {
                return $this->errorResponse('Invalid request', 400);
            }

            $this->authService->resetPasswordFirstLogin($supplier, $request->password);

            return $this->successResponse(null, 'Password reset successfully. Please login with your new password.');
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

            return $this->successResponse([
                'supplier' => new SupplierResource($result['supplier']),
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

            if (!$supplier) {
                return $this->unauthorizedResponse('Not authenticated');
            }

            return $this->successResponse(
                new SupplierResource($supplier),
                'Supplier retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier');
        }
    }
}

