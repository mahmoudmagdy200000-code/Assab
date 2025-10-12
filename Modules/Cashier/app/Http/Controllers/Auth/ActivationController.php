<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Entities\Cashier;
use Modules\Cashier\Http\Requests\Auth\ActivationRequest;

class ActivationController extends Controller
{
    /**
     * Activate cashier account
     */
    public function activate(ActivationRequest $request): JsonResponse
    {
        $cashier = Cashier::where('email', $request->email)->first();

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
            'cashier' => [
                'id' => $cashier->id,
                'name' => $cashier->name,
                'email' => $cashier->email,
                'status' => $cashier->status,
            ]
        ], 'Account activated successfully. You can now login.');
    }
}
