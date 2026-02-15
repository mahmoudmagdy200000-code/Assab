<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Http\Requests\Auth\ActivationRequest;
use Modules\Cashier\Models\Cashier;

class ActivationController extends BaseController
{
    /**
     * Activate cashier account (3.2.1.1)
     * Email/Phone + default password + new password. After reset, redirect to login (no token).
     */
    public function activate(ActivationRequest $request): JsonResponse
    {
        $identifier = $request->identifier;
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $cashier = Cashier::where($field, $identifier)->first();

        if (!$cashier) {
            return $this->errorResponse('Cashier not found.', 404);
        }

        if ($cashier->isActive()) {
            return $this->errorResponse('Account is already activated. Please log in.', 400);
        }

        if (!Hash::check($request->default_password, $cashier->password)) {
            return $this->errorResponse('Invalid default password. Please use the password provided by your branch manager.', 401);
        }

        $cashier->update([
            'password' => Hash::make($request->password),
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return $this->successResponse([
            'message' => 'Account activated successfully. Please log in with your new password.',
            'redirect_to_login' => true,
        ], 'Account activated successfully. Please log in with your new password.');
    }
}
