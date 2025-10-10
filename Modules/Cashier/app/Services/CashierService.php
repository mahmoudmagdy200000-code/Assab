<?php

namespace Modules\Cashier\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Cashier\Http\Requests\StoreCashierRequest;
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
    /**
     * Create a new cashier under the branch of the authenticated manager
     */
    public function store(StoreCashierRequest $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $data = $request->validated();
            $data['branch_id'] = $user->branch_id;
            $data['created_by'] = $user->id;


            $cashier = Cashier::create($data);


            if ($request->filled('shift_ids')) {
                $shiftIds = $request->shift_ids;


                $occupiedShifts = \Modules\Cashier\Models\CashierShift::whereIn('shift_id', $shiftIds)->pluck('shift_id')->toArray();
                $availableShifts = array_diff($shiftIds, $occupiedShifts);

                if (count($availableShifts) !== count($shiftIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more selected shifts are already assigned to another cashier.',
                    ], 422);
                }

            
                $cashier->shifts()->attach($availableShifts);
            }

            return $cashier;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating cashier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
