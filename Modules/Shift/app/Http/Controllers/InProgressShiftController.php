<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shift\Repositories\CashierShiftRepositoryInterface;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\CashierShiftResource;
use Modules\Shift\Transformers\ShiftDetailResource;

class InProgressShiftController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService,
        private CashierShiftRepositoryInterface $cashierShiftRepository
    ) {}

    /**
     * Display a listing of in-progress shifts
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Ensure the user is a branch manager
            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $managerBranchId = $manager->branch_id;
            $cashierId = $request->input('cashier_id');

            $inProgressShifts = $this->cashierShiftRepository->getInProgressPaginated($managerBranchId, $cashierId, 10);

            return $this->paginatedResponse(
                CashierShiftResource::collection($inProgressShifts),
                'In-progress shifts retrieved successfully',
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shifts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified in-progress shift
     */
    public function show(string $shift): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Ensure the user is a branch manager
            if (! $manager || ! $manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $managerBranchId = $manager->branch_id;

            $shiftDetails = $this->cashierShiftRepository->findForManagerShow($shift, $managerBranchId);

            if ($shiftDetails->status->value !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in progress',
                ], 400);
            }

            $progress = $this->shiftService->getShiftProgress($shift);

            $elapsedMinutes = $shiftDetails->actual_start_time->diffInMinutes(now());
            $totalMinutes = $shiftDetails->actual_start_time->diffInMinutes(
                $shiftDetails->shift->end_time
            );

            return $this->successResponse(
                new ShiftDetailResource($shiftDetails, $progress, $elapsedMinutes, $totalMinutes),
                'Shift details retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found or you do not have access to it',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shift details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
