<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Http\Requests\Auth\ActivationRequest;

class ActivationController extends Controller
{
    /**
     * Activate cashier account
     */
    public function activate(ActivationRequest $request): JsonResponse
    {
        $identifier = $request->identifier;
        
        // Check if identifier is email or phone
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $cashier = Cashier::where($field, $identifier)->first();

        if (!$cashier) {
            return response()->error('Cashier not found', 404);
        }

        if ($cashier->isActive()) {
            return response()->error('Account is already activated', 400);
        }

        // Update password and activate
        $cashier->update([
            'password' => Hash::make($request->password),
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return response()->success([
            'user' => [
                'id' => $cashier->id,
                'name' => $cashier->name,
                'email' => $cashier->email,
                'phone' => $cashier->phone,
                'image' => $cashier->image_url ?? null,
                'created_at' => $cashier->created_at?->format('Y-m-d H:i:s'),
            ],
            'token' => null, // No token on activation, user needs to login
            'requires_password_reset' => false,
        ], 'Account activated successfully. You can now login.');
    }
}
