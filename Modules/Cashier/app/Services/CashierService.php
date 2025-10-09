<?php

namespace Modules\Cashier\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Transformers\CashierResource;

class CashierService
{


    /**
     * Get all cashiers under the branch of the authenticated manager
     */
    public function index()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $perPage = request('per_page', config('pagination.count', 10));

            $cashiers = Cashier::where('branch_id', $user->branch_id)
                ->with(['branch', 'creator', 'shifts'])
                ->paginate($perPage);

            return CashierResource::collection($cashiers);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving cashiers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
