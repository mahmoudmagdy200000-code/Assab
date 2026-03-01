<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Services\VarianceCalculationService;
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
        try {
            $manager = auth()->user();
            
            // Ensure the user is a branch manager
            if (!$manager || !$manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
            
            $managerBranchId = $manager->branch_id;
            $filters = $request->only(['date_from', 'date_to', 'cashier_id']);
            $filters['branch_id'] = $managerBranchId;

            $shifts = $this->shiftService->getCompletedShifts(
                cashierId: $request->input('cashier_id'),
                filters: $filters
            );

            return $this->paginatedResponse(
                ShiftDetailResource::collection($shifts),
                'Completed shifts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified completed shift
     */
    public function show(string $shiftId): JsonResponse
    {
        try {
            $manager = auth()->user();
            
            // Ensure the user is a branch manager
            if (!$manager || !$manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
            
            $managerBranchId = $manager->branch_id;

            $shift = CashierShift::with([
                'cashier',
                'shift',
                'nextCashier',
                'originalCashier',
                'reassignedBy',
                'salesBreakdown.aggregator',
                'handoverStatus.reviewedBy',
                'handover',
                'varianceDetails.responsibleCashier',
                'varianceAlerts',
                'history'
            ])
            ->whereHas('shift', function($q) use ($managerBranchId) {
                $q->where('branch_id', $managerBranchId);
            })
            ->whereHas('cashier', function($q) use ($managerBranchId) {
                $q->where('branch_id', $managerBranchId);
            })
            ->findOrFail($shiftId);

            $progress = $this->shiftService->getShiftProgress($shiftId);

            $variance = null;
            if ($shift->hasVariance()) {
                $variance = $this->varianceService->getVarianceFormatted($shift);
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift details retrieved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shift),
                    'progress' => $progress,
                    'variance' => $variance,
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
