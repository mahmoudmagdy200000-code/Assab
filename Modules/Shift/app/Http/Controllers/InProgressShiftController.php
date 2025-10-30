<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\{CashierShiftResource, ShiftResource, ShiftDetailResource};

class InProgressShiftController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService
    ) {}

    /**
     * Display a listing of in-progress shifts (today only)
     *
     * @param Request $request
     * @return JsonResponse
     */



    public function index(Request $request): JsonResponse
    {
        try {
            $cashierId = $request->input('cashier_id');

            // Get paginated in-progress shifts
            $inProgressShifts = CashierShift::inProgress()
                ->with(['cashier', 'shift'])
                ->orderBy('actual_start_time')
                ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
                ->paginate(10);

            // Get next shift
            $nextShift = CashierShift::where('status', 'not_started')
                ->whereDate('shift_date', today())
                ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
                ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
                ->orderBy('shifts.start_time')
                ->select('cashier_shifts.*')
                ->with(['cashier', 'shift'])
                ->first();

            return $this->paginatedResponse(
                CashierShiftResource::collection($inProgressShifts),
                'In-progress shifts retrieved successfully',
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shifts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified in-progress shift
     *
     * @param int $shift
     * @return JsonResponse
     */
    public function show(int $shift): JsonResponse
    {
        try {
            $shiftDetails = $this->shiftService->getShiftDetails($shift);

            // Verify shift is in progress
            if ($shiftDetails->status->value !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in progress',
                ], 400);
            }

            // Get real-time progress
            $progress = $this->shiftService->getShiftProgress($shift);

            // Calculate elapsed time
            $elapsedMinutes = $shiftDetails->actual_start_time->diffInMinutes(now());
            $totalMinutes = $shiftDetails->actual_start_time->diffInMinutes(
                $shiftDetails->shift->end_time
            );

            return $this->successResponse(
                new ShiftDetailResource($shiftDetails, $progress, $elapsedMinutes, $totalMinutes),
                'Shift details retrieved successfully'
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shift details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
