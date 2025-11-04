<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Services\VarianceCalculationService;
use Modules\Shift\Transformers\CashierShiftResource;
use Modules\Shift\Transformers\ShiftDetailResource;
use Modules\Shift\Models\CashierShift;

class CompletedShiftController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService,
        private VarianceCalculationService $varianceService
    ) {}

    /**
     * Display a listing of completed shifts
     */
    public function index(Request $request): JsonResponse
    {
        $managerBranchId = $request->manager_branch_id;
        $filters = $request->only(['date_from', 'date_to', 'cashier_id']);
        $filters['branch_id'] = $managerBranchId;

        $shifts = $this->shiftService->getCompletedShifts(
            cashierId: $request->input('cashier_id'),
            filters: $filters
        );

        return $this->paginatedResponse(
            CashierShiftResource::collection($shifts),
            'Completed shifts retrieved successfully'
        );
    }

    /**
     * Display the specified completed shift
     */
    public function show(int $shiftId): JsonResponse
    {
        try {
            $managerBranchId = request()->manager_branch_id;

            $shift = CashierShift::with([
                'cashier',
                'shift',
                'nextCashier',
                'originalCashier',
                'reassignedBy',
                'salesBreakdown.aggregator',
                'handoverStatus.reviewedBy',
                'varianceDetails.responsibleCashier',
                'varianceAlerts',
                'history'
            ])
            ->whereHas('shift', function($q) use ($managerBranchId) {
                $q->where('branch_id', $managerBranchId);
            })
            ->findOrFail($shiftId);

            $progress = $this->shiftService->getShiftProgress($shiftId);

            $varianceDetails = null;
            if ($shift->hasVariance()) {
                $varianceDetails = $this->varianceService->getVarianceDetails($shift);
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift details retrieved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shift),
                    'progress' => $progress,
                    'variance_details' => $varianceDetails,
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found or you do not have access to it',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shift details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
