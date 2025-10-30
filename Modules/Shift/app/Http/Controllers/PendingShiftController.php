<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Faker\Provider\Base;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\{CashierShiftResource, ShiftResource, ShiftDetailResource};
use Modules\Shift\Models\CashierShift;

class PendingShiftController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService
    ) {}

    /**
     * Display a listing of pending shifts
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cashierId = $request->input('cashier_id');

            $shifts = $this->shiftService->getPendingShifts($cashierId);

            return $this->paginatedResponse(
                CashierShiftResource::collection($shifts),
                'Pending shifts retrieved successfully',
                [
                    'total' => $shifts->count(),
                ]
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending shifts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified pending shift
     *
     * @param int $shift
     * @return JsonResponse
     */
    public function show(int $shift): JsonResponse
    {
        try {
            $shiftDetails = $this->shiftService->getShiftDetails($shift);

            // Verify shift is pending
            if ($shiftDetails->status->value !== 'not_started') {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in pending status',
                ], 400);
            }

            // Get shift progress
            $progress = $this->shiftService->getShiftProgress($shift);

            return response()->json([
                'success' => true,
                'message' => 'Pending shift details retrieved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftDetails),
                    'progress' => $progress,
                    'actions_available' => [
                        'view_details' => true,
                        'reassign_shift' => true,
                        'start_shift' => $shiftDetails->shift_date->isToday(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shift details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
