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
     * Handle cashier login (3.2.1.1)
     * Email/Phone and password. "Remember Me" extends token expiry.
     * Pending accounts must activate first with default password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->identifier;
        $password = $request->password;
        $rememberMe = $request->boolean('remember_me');

        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $cashier = Cashier::where($field, $identifier)->first();

        if (!$cashier) {
            return $this->errorResponse('Invalid credentials or inactive account.', 401);
        }

        if ($cashier->isPending()) {
            return $this->errorResponse(
                'Please activate your account using the default password provided by your branch manager.',
                403
            );
        }

        if (!$cashier->isActive()) {
            return $this->errorResponse(
                'Your account is not active. Please contact your manager.',
                403
            );
        }

        if (!Hash::check($password, $cashier->password)) {
            return $this->errorResponse('Invalid credentials or inactive account.', 401);
        }

        $expiresAt = $rememberMe ? now()->addDays(30) : null;
        $token = $expiresAt
            ? $cashier->createToken('cashier-token', ['*'], $expiresAt)->plainTextToken
            : $cashier->createToken('cashier-token')->plainTextToken;

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
     * Handle cashier logout (3.2.1.4)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(null, 'Logged out successfully');
    }
}
