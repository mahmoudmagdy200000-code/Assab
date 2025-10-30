<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Faker\Provider\Base;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\{ShiftResource, ShiftDetailResource};

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
                ->get();


            $nextShift = CashierShift::where('status', 'not_started')
                ->whereDate('shift_date', today())
                ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
                ->when($cashierId, fn($q) => $q->where('cashier_id', $cashierId))
                ->orderBy('shifts.start_time')
                ->select('cashier_shifts.*')
                ->with(['cashier', 'shift'])
                ->first();


            return $this->successResponse([
             CashierShift::collection($inProgressShifts),
             'inprogress shifts retrieved successfully',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
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

            return response()->json([
                'success' => true,
                'message' => 'In-progress shift details retrieved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftDetails),
                    'progress' => $progress,
                    'real_time' => [
                        'elapsed_minutes' => $elapsedMinutes,
                        'total_minutes' => $totalMinutes,
                        'remaining_minutes' => max(0, $totalMinutes - $elapsedMinutes),
                        'progress_percentage' => min(100, ($elapsedMinutes / $totalMinutes) * 100),
                    ],
                    'actions_available' => [
                        'view_details' => true,
                        'end_shift' => true,
                        'end_shift_with_handover' => true,
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
