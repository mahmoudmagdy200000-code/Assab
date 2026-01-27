<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shift\Helpers\ShiftHelper;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftService;
use Modules\Shift\Transformers\CashierShiftCollection;
use Modules\Shift\Transformers\ShiftDetailResource;

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
            $manager = auth()->user();
            
            // Ensure the user is a branch manager
            if (!$manager || !$manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
            
            $managerBranchId = $manager->branch_id;
            $cashierId = $request->input('cashier_id');

            $shifts = CashierShift::upcoming()
                ->with(['cashier', 'shift', 'nextCashier', 'originalCashier', 'reassignedBy', 'handover', 'handoverStatus'])
                ->whereHas('shift', fn($q) => $q->where('branch_id', $managerBranchId))
                ->whereHas('cashier', fn($q) => $q->where('branch_id', $managerBranchId))
                ->whereDate('shift_date', '>=', now()->subMonth())
                ->whereDate('shift_date', '<=', now()->addMonth())
                ->when($cashierId, fn($q, $cashierId) => $q->where('cashier_id', $cashierId))
                ->orderBy('shift_date')
                ->paginate(10);

            foreach ($shifts as $cs) {
                $cs->setAttribute('computed_next_cashier', $this->shiftService->getNextShiftCashier($cs));
            }

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
            $manager = auth()->user();
            
            // Ensure the user is a branch manager
            if (!$manager || !$manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
            
            $managerBranchId = $manager->branch_id;

            // تحقق من أن الشيفت تابع لبرانش المدير
            $shiftDetails = CashierShift::with([
                'cashier',
                'shift',
                'nextCashier',
                'originalCashier',
                'reassignedBy',
                'salesBreakdown.aggregator',
                'handoverStatus.reviewedBy',
                'handover',
                'varianceDetails.responsibleCashier',
                'varianceAlerts',
                'history'
            ])
                ->whereHas('shift', function ($q) use ($managerBranchId) {
                    $q->where('branch_id', $managerBranchId);
                })
                ->whereHas('cashier', function ($q) use ($managerBranchId) {
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

    /**
     * Pending shifts for a specific cashier (current work week).
     * GET /shifts/pending/cashiers/{cashier}
     */
    public function getPendingShiftByCashierId(Request $request, string $cashier): JsonResponse
    {
        try {
            $manager = auth()->user();

            if (!$manager || !$manager->branch_id) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $weekStart = $request->query('week_start');
            $refDate = $weekStart ? Carbon::parse($weekStart) : Carbon::today();
            [$start, $end] = ShiftHelper::workWeekDatesFor($refDate);

            $shifts = CashierShift::upcoming()
                ->where('cashier_id', $cashier)
                ->whereDate('shift_date', '>=', $start)
                ->whereDate('shift_date', '<=', $end)
                ->whereHas('shift', fn ($q) => $q->where('branch_id', $manager->branch_id))
                ->whereHas('cashier', fn ($q) => $q->where('branch_id', $manager->branch_id))
                ->with([
                    'cashier',
                    'shift',
                    'nextCashier',
                    'originalCashier',
                    'reassignedBy',
                    'handover',
                    'handoverStatus',
                ])
                ->orderBy('shift_date')
                ->paginate($request->input('per_page', 10));

            foreach ($shifts as $cs) {
                $cs->setAttribute('computed_next_cashier', $this->shiftService->getNextShiftCashier($cs));
            }

            return $this->paginatedResponse(
                new CashierShiftCollection($shifts),
                'Pending shifts for cashier retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
