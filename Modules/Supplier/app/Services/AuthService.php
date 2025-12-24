<?php

namespace Modules\Supplier\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Supplier\Models\Supplier;

class AuthService
{
    /**
     * Handle first login
     */
    public function firstLogin(string $identifier, string $password): array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $supplier = Supplier::where($field, $identifier)->first();

        if (!$supplier || !Hash::check($password, $supplier->password)) {
            throw new \Exception('Invalid credentials');
        }

        if (!$supplier->isActive()) {
            throw new \Exception('Account is inactive');
        }

        if (!$supplier->isFirstLogin()) {
            throw new \Exception('Account is already activated. Please use regular login.');
        }

        $token = $supplier->createToken('first-login-token')->plainTextToken;
        
        return [
            'supplier' => $supplier,
            'token' => $token,
        ];
    }

    /**
     * Reset password on first login
     */
    public function resetPasswordFirstLogin(Supplier $supplier, string $newPassword): Supplier
    {
        $supplier->update([
            'password' => Hash::make($newPassword),
            'is_first_login' => false,
        ]);

        return $supplier;
    }

    /**
     * Handle regular login
     */
    public function login(string $identifier, string $password, bool $remember = false): array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $supplier = Supplier::where($field, $identifier)->first();

        if (!$supplier) {
            Log::warning('Supplier login attempt with non-existent identifier', [
                'identifier' => $identifier,
                'field' => $field,
            ]);
            throw new \Exception('Invalid credentials');
        }

        // Check password - note: password is cast as 'hashed' in model, so $supplier->password is already hashed
        if (!Hash::check($password, $supplier->password)) {
            Log::warning('Supplier login attempt with invalid password', [
                'supplier_id' => $supplier->id,
                'identifier' => $identifier,
            ]);
            throw new \Exception('Invalid credentials');
        }

        if (!$supplier->isActive()) {
            throw new \Exception('Account is inactive');
        }

        if ($supplier->isFirstLogin()) {
            throw new \Exception('Please complete first login setup');
        }

        // Update last seen
        $supplier->updateLastSeen();

        // Create token for API authentication
        $tokenName = $remember ? 'supplier-remember-token' : 'supplier-token';
        $token = $supplier->createToken($tokenName)->plainTextToken;

        return [
            'supplier' => $supplier,
            'token' => $token,
        ];
    }

    /**
     * Logout
     */
    public function logout(Supplier $supplier): void
    {
        $supplier->currentAccessToken()->delete();
    }

    /**
     * Logout from all devices
     */
    public function logoutAll(Supplier $supplier): void
    {
        $supplier->tokens()->delete();
    }
}

