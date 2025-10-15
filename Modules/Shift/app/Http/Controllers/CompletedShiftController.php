<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Services\VarianceCalculationService;
use Modules\Shift\Http\Resources\ShiftResource;
use Modules\Shift\Http\Resources\ShiftDetailResource;

class CompletedShiftController extends Controller
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

        return response()->json([
            'success' => true,
            'message' => 'Completed shifts retrieved successfully',
            'data' => ShiftResource::collection($shifts),
            'meta' => [
                'total' => $shifts->count(),
                'filters_applied' => !empty($filters),
            ]
        ]);
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
                'shift' => new ShiftDetailResource($shift),
                'progress' => $progress,
                'variance_details' => $varianceDetails,
            ]
        ]);
    }
}
