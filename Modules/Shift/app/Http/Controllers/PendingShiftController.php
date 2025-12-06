<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\{CashierShiftCollection, ShiftDetailResource};
use Modules\Shift\Models\CashierShift;

class PendingShiftController extends BaseController
{
    public function __construct(
        private ShiftService $shiftService
    ) {}

    /**
     * Display a listing of pending shifts
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $managerBranchId = auth()->user()->branch_id;
            // من الـ middleware
            $cashierId = $request->input('cashier_id');

            $shifts = CashierShift::upcoming()
                ->with(['cashier', 'shift', 'nextCashier', 'originalCashier', 'reassignedBy'])
                ->whereHas('shift', fn($q) => $q->where('branch_id', auth()->user()->branch_id))
                ->whereDate('shift_date', '>=', now()->subMonth())
                ->whereDate('shift_date', '<=', now()->addMonth())
                ->when($cashierId, fn($q, $cashierId) => $q->where('cashier_id', $cashierId))
                ->orderBy('shift_date')
                ->paginate(10);


            return $this->paginatedResponse(
                new CashierShiftCollection($shifts),
                'Pending shifts retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified pending shift
     */
    public function show(string $shift): JsonResponse
    {
        try {
            $managerBranchId = request()->manager_branch_id;

            // تحقق من أن الشيفت تابع لبرانش المدير
            $shiftDetails = CashierShift::with([
                'cashier',
                'shift',
                'nextCashier',
                'originalCashier',
                'reassignedBy',
                'salesBreakdown.aggregator',
                'handoverStatus.reviewedBy',
                'varianceDetails.responsibleCashier',
                'varianceAlerts',
                'history'
            ])
                ->whereHas('shift', function ($q) use ($managerBranchId) {
                    $q->where('branch_id', $managerBranchId);
                })
                ->findOrFail($shift);

            if ($shiftDetails->status->value !== 'not_started') {
                return response()->json([
                    'success' => false,
                    'message' => 'This shift is not in pending status',
                ], 400);
            }

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
