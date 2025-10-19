<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\{ShiftResource, ShiftDetailResource};
use Modules\Shift\Models\CashierShift;

class PendingShiftController extends Controller
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

            return response()->json([
                'success' => true,
                'message' => 'Pending shifts retrieved successfully',
                'data' => ShiftResource::collection($shifts),
                'meta' => [
                    'total' => $shifts->count(),
                    'date_range' => [
                        'from' => now()->format('Y-m-d'),
                        'to' => now()->addMonth()->format('Y-m-d'),
                    ],
                    'next_shift' => $shifts->first() ? [
                        'id' => $shifts->first()->id,
                        'date' => $shifts->first()->shift_date->format('Y-m-d'),
                        'time' => $shifts->first()->shift->start_time,
                        'cashier' => $shifts->first()->cashier->name,
                    ] : null,
                ]
            ]);
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
