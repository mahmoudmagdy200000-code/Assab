<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Http\Requests\Auth\LoginRequest;
use Modules\Cashier\Helpers\CashierHelper;
use App\Http\Controllers\BaseController;
use Modules\Cashier\Transformers\CashierResource;

class LoginController extends BaseController
{
    /**
     * Handle cashier login
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');

        // Find cashier
        $cashier = Cashier::where('email', $credentials['email'])->first();

        if (!$cashier || !Hash::check($credentials['password'], $cashier->password)) {
            return $this->error('Invalid credentials', 401);
        }

        // Check if account is active
        if (!$cashier->isActive()) {
            return $this->error(
                'Your account is not active. Please contact your manager.',
                403
            );
        }

        // Create token
        $token = $cashier->createToken('cashier-token')->plainTextToken;

        return $this->successResponse([
            'cashier' => new CashierResource($cashier),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Handle cashier logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse('Logged out successfully');
    }
}
