<?php

namespace Modules\Cashier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cashier\Services\CashierService;
use Modules\Cashier\Transformers\AvailableForShiftResource;
use Modules\Cashier\Transformers\CashierResource;

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

        return $this->successResponse($stats, 'Statistics retrieved successfully');
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

        return $this->successResponse($result, 'Shifts assigned successfully');
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

        return $this->successResponse($result, 'Shifts updated successfully');
    }

    /**
     * Atomically swap cashier shift assignments.
     */
    public function swapShifts(Request $request): JsonResponse
    {
        $request->validate([
            'cashier_a_id' => 'required|exists:cashiers,id',
            'shift_a_id' => 'required|exists:shifts,id',
            'cashier_b_id' => 'required|exists:cashiers,id',
            'shift_b_id' => 'required|exists:shifts,id',
            'shift_date' => 'required|date',
            'for_full_week' => 'nullable|boolean',
        ]);

        $result = $this->cashierService->swapCashierShifts(
            cashierAId: $request->cashier_a_id,
            shiftAId: $request->shift_a_id,
            cashierBId: $request->cashier_b_id,
            shiftBId: $request->shift_b_id,
            shiftDate: $request->shift_date,
            forFullWeek: (bool) $request->input('for_full_week', false)
        );

        return $this->successResponse($result, 'Cashier shifts swapped successfully');
    }

    /**
     * Get branch shift templates along with assigned cashiers for a date range or default scheduling week.
     */
    public function datedRoster(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
        ]);

        $result = $this->cashierService->getDatedBranchRoster(
            startDate: $request->start_date,
            endDate: $request->end_date
        );

        return $this->successResponse($result, 'Dated branch roster retrieved successfully');
    }

    /**
     * Save recurring roster assignments (P4b / MT-23).
     */
    public function saveRosterAssignments(Request $request): JsonResponse
    {
        // Support either bulk 'assignments' array or a single object in the body
        if ($request->has('assignments') && is_array($request->assignments)) {
            $request->validate([
                'assignments' => 'required|array|min:1',
                'assignments.*.shift_id' => 'required|exists:shifts,id',
                'assignments.*.cashier_id' => 'required|exists:cashiers,id',
                'assignments.*.effective_from' => 'nullable|date',
                'assignments.*.effective_to' => 'nullable|date|after_or_equal:assignments.*.effective_from',
            ]);
            $assignments = $request->assignments;
        } else {
            $request->validate([
                'shift_id' => 'required|exists:shifts,id',
                'cashier_id' => 'required|exists:cashiers,id',
                'effective_from' => 'nullable|date',
                'effective_to' => 'nullable|date|after_or_equal:effective_from',
            ]);
            $assignments = [$request->only(['shift_id', 'cashier_id', 'effective_from', 'effective_to'])];
        }

        $result = $this->cashierService->saveRosterAssignments($assignments);

        return $this->successResponse($result, 'Roster assignments saved successfully');
    }

    /**
     * Get recurring roster assignments for the branch.
     */
    public function getRosterAssignments(Request $request): JsonResponse
    {
        $result = $this->cashierService->getRosterAssignments();

        return $this->successResponse($result, 'Roster assignments retrieved successfully');
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

        return $this->successResponse(
            new CashierResource($cashier),
            'Activation link resent successfully'
        );
    }
}
