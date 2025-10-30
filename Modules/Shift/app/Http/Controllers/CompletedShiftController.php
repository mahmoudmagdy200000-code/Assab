<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Services\VarianceCalculationService;
use Modules\Shift\Http\Resources\ShiftResource;
use Modules\Shift\Http\Resources\ShiftDetailResource;
use Modules\Shift\Transformers\CashierShiftResource;
use Modules\Shift\Transformers\ShiftDetailResource as TransformersShiftDetailResource;
use Modules\Shift\Transformers\ShiftResource as TransformersShiftResource;

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
        $filters = $request->only(['date_from', 'date_to', 'cashier_id']);

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
        $shift = $this->shiftService->getShiftDetails($shiftId);

        // Get shift progress
        $progress = $this->shiftService->getShiftProgress($shiftId);

        // Get variance details if variance exists
        $varianceDetails = null;
        if ($shift->hasVariance()) {
            $varianceDetails = $this->varianceService->getVarianceDetails($shift);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shift details retrieved successfully',
            'data' => [
                'shift' => new TransformersShiftDetailResource($shift),
                'progress' => $progress,
                'variance_details' => $varianceDetails,
            ]
        ]);
    }
}
