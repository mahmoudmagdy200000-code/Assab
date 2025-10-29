<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Faker\Provider\Base;
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


            $inProgressShifts = CashierShift::inProgress()
                ->with(['cashier', 'shift'])
                ->orderBy('actual_start_time')
                ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
                ->paginate(10);


            $nextShift = CashierShift::where('status', 'not_started')
                ->whereDate('shift_date', today())
                ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
                ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
                ->orderBy('shifts.start_time')
                ->select('cashier_shifts.*') // مهم لإرجاع بيانات CashierShift فقط
                ->with(['cashier', 'shift'])
                ->first();


            return $this->paginatedResponse(
                CashierShiftResource::collection($inProgressShifts),
                'In-progress shifts retrieved successfully',
                $nextShift ? new CashierShiftResource($nextShift) : null
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shifts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //     public function index(Request $request): JsonResponse
    // {
    //     try {
    //         $cashierId = $request->input('cashier_id');


    //         $shifts = $this->shiftService->getInProgressShifts($cashierId);

    //         $firstShift = $shifts->first();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'In-progress shifts retrieved successfully',
    //             'data' => ShiftResource::collection($shifts),
    //             'meta' => [
    //                 'total' => $shifts->count(),
    //                 'date' => now()->format('Y-m-d'),
    //                 'next_shift_to_begin' => $firstShift ? [
    //                     'id' => $firstShift->id,
    //                     'cashier' => $firstShift->cashier?->name ?? 'N/A',
    //                     'start_time' => $firstShift->actual_start_time?->format('Y-m-d H:i:s') ?? null,
    //                     'expected_end' => $firstShift->shift?->end_time?->format('Y-m-d H:i:s') ?? null,
    //                 ] : null,
    //             ],
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to retrieve in-progress shifts',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

            // Add progress data to the shift details
            $shiftDetails->progress_data = [
                'progress' => $progress,
                'elapsed_minutes' => $elapsedMinutes,
                'total_minutes' => $totalMinutes,
            ];

            return $this->successResponse(
                new ShiftDetailResource($shiftDetails),
                'In-progress shift details retrieved successfully'
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shift details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getInProgressShiftByCashierId($id): JsonResponse
    {
        try {
            $shift = CashierShift::inProgress()
                ->where('cashier_id', $id)
                ->with(['cashier', 'shift'])
                ->first();

            if (!$shift) {
                return response()->json([
                    'success' => false,
                    'message' => 'No in-progress shift found for this cashier',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'In-progress shift retrieved successfully',
                'data' => new ShiftDetailResource($shift),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve in-progress shift',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
