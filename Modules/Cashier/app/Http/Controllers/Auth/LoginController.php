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
        $identifier = $request->identifier;
        $password = $request->password;

        // Check if identifier is email or phone
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        
        // Find cashier
        $cashier = Cashier::where($field, $identifier)->first();

        if (!$cashier || !Hash::check($password, $cashier->password)) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        // Check if account is active
        if (!$cashier->isActive()) {
            return $this->errorResponse(
                'Your account is not active. Please contact your manager.',
                403
            );
        }

        // Create token
        $token = $cashier->createToken('cashier-token')->plainTextToken;

        return $this->successResponse([
            'user' => [
                'id' => $cashier->id,
                'name' => $cashier->name,
                'email' => $cashier->email,
                'phone' => $cashier->phone,
                'image' => $cashier->image_url ?? null,
                'created_at' => $cashier->created_at?->format('Y-m-d H:i:s'),
            ],
            'token' => $token,
        ], 'Login successful');
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
