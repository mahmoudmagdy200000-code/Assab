<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Entities\Cashier;
use Modules\Cashier\Http\Requests\Auth\LoginRequest;

class LoginController extends Controller
{
    /**
     * Handle cashier login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        // Find cashier
        $cashier = Cashier::where('email', $credentials['email'])->first();

        if (!$cashier || !Hash::check($credentials['password'], $cashier->password)) {
            return response()->error('Invalid credentials', 401);
        }

        // Check if account is active
        if (!$cashier->isActive()) {
            return response()->error(
                'Your account is not active. Please contact your manager.',
                403
            );
        }

        // Create token
        $token = $cashier->createToken('cashier-token')->plainTextToken;

        return response()->success([
            'cashier' => [
                'id' => $cashier->id,
                'name' => $cashier->name,
                'email' => $cashier->email,
                'phone' => $cashier->phone,
                'image' => $cashier->image_url,
                'branch' => [
                    'id' => $cashier->branch->id,
                    'name' => $cashier->branch->name,
                ],
                'status' => $cashier->status,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    /**
     * Handle cashier logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->success(null, 'Logged out successfully');
    }
}
