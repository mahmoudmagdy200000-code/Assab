<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\{CashierShiftCollection, CashierShiftResource, ShiftResource, ShiftDetailResource};
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

            // Transform collection items to ensure next_cashier is present
            $shifts->getCollection()->transform(function ($shift) {
                return [
                    'id' => $shift->id,
                    'cashier_id' => $shift->cashier_id,
                    'shift_id' => $shift->shift_id,
                    'shift_date' => $shift->shift_date?->toDateString(),
                    'status' => $shift->status?->value,
                    'opening_balance' => $shift->opening_balance,
                    'closing_balance' => $shift->closing_balance,
                    'cashier' => $shift->cashier,
                    'shift' => $shift->shift,
                    'next_cashier' => $shift->nextCashier ? [
                        'id' => $shift->nextCashier->id,
                        'name' => $shift->nextCashier->name ?? null,
                        'email' => $shift->nextCashier->email ?? null,
                    ] : null,
                ];
            });

            return response()->json([
                'message' => 'Pending shifts retrieved successfully',
                'data' => $shifts,
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
