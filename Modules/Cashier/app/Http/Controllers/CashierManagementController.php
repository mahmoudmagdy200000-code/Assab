<?php

namespace Modules\Cashier\Http\Controllers;


use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cashier\Services\CashierService;
use Modules\Cashier\Transformers\AvailableForShiftResource;
use Modules\Cashier\Transformers\CashierResource;
use App\Http\Controllers\BaseController;

class CashierManagementController extends BaseController
{
    public function __construct(
        private CashierService $cashierService
    ) {
        $this->middleware('branch.manager');
    }

    /**
     * Search cashiers by name
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $cashiers = $this->cashierService->searchCashiers(
            search: $request->query->get('query'),
            branchId: auth()->user()->branch_id
        );

        return $this->paginatedResponse(
            CashierResource::collection($cashiers),
            'Search results retrieved successfully',
        );
    }

    /**
     * Get cashier statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $branchId = auth()->user()->branch_id;

        $stats = $this->cashierService->getCashierStatistics($branchId);

        return  $this->paginatedResponse(
            CashierResource::collection($stats),
            'Statistics retrieved successfully',
        );
    }

    /**
     * Get available cashiers for shift assignment
     */
    public function availableForShift(Request $request): JsonResponse
    {
        $request->validate([
            'shift_id' => 'required|exists:shifts,id',
            'shift_date' => 'required|date',
        ]);

        $availableCashiers = $this->cashierService->getAvailableCashiersForShift(
            shiftId: $request->shift_id,
            shiftDate: $request->shift_date,
            branchId: auth()->user()->branch_id
        );

        return $this->paginatedResponse(
            AvailableForShiftResource::collection($availableCashiers),
            'Available cashiers retrieved successfully'
        );
    }

    /**
     * Assign shifts to cashier
     */
    public function assignShifts(Request $request): JsonResponse
    {
        $request->validate([
            'cashier_id' => 'required|exists:cashiers,id',
            'shift_ids' => 'required|array|min:1',
            'shift_ids.*' => 'exists:shifts,id',
            'shift_date' => 'required|date',
        ]);

        $result = $this->cashierService->assignShiftsToCashier(
            cashierId: $request->cashier_id,
            shiftIds: $request->shift_ids,
            shiftDate: $request->shift_date
        );

        return  $this->successResponse($result, 'Shifts assigned successfully');
    }

    /**
     * Update cashier shifts
     */
    public function updateShifts(Request $request): JsonResponse
    {
        $request->validate([
            'cashier_id' => 'required|exists:cashiers,id',
            'shift_ids' => 'required|array|min:1',
            'shift_ids.*' => 'exists:shifts,id',
        ]);

        $result = $this->cashierService->updateCashierShifts(
            cashierId: $request->cashier_id,
            shiftIds: $request->shift_ids
        );

        return  $this->successResponse($result, 'Shifts updated successfully');
    }

    /**
     * Resend activation link
     */
    public function resendActivation(Request $request): JsonResponse
    {
        $request->validate([
            'cashier_id' => 'required|exists:cashiers,id',
        ]);

        $cashier = $this->cashierService->resendActivationLink($request->cashier_id);

        return  $this->successResponse(
            new CashierResource($cashier),
            'Activation link resent successfully'
        );
    }
}
