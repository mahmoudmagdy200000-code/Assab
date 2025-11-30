<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Transformers\BranchManagerShiftResource;

class BranchManagerShiftController extends BaseController
{
    public function __construct(
        private BranchManagerShiftService $shiftService
    ) {}

    /**
     * Get current shift (today's shift) - Section A & B
     */
    public function current(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();
            $managerShift = $this->shiftService->getOrCreateTodayShift(
                $manager->id,
                $manager->branch_id
            );

            $progress = $this->shiftService->getShiftProgress($managerShift);
            $breakdown = $this->shiftService->getCashierShiftsBreakdown($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'progress' => $progress,
                'cashier_shifts_breakdown' => $breakdown,
                'can_start' => $managerShift->canStart(),
                'can_end' => $managerShift->canEnd(),
            ], 'Current shift retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get shift history
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $query = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->with(['branch', 'nextManager'])
                ->orderBy('shift_date', 'desc');

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            if ($from = $request->input('date_from')) {
                $query->whereDate('shift_date', '>=', $from);
            }

            if ($to = $request->input('date_to')) {
                $query->whereDate('shift_date', '<=', $to);
            }

            $shifts = $query->paginate($request->input('per_page', 10));

            return $this->paginatedResponse(
                BranchManagerShiftResource::collection($shifts),
                'Shifts history retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get shift details - Section B
     */
    public function show(string $id): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->with(['branch', 'nextManager', 'approvedBy'])
                ->findOrFail($id);

            $progress = $this->shiftService->getShiftProgress($managerShift);
            $summary = $this->shiftService->getShiftSummary($managerShift);
            $breakdown = $this->shiftService->getCashierShiftsBreakdown($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'progress' => $progress,
                'summary' => $summary,
                'cashier_shifts_breakdown' => $breakdown,
            ], 'Shift details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Start shift - Section A
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = $this->shiftService->getOrCreateTodayShift(
                $manager->id,
                $manager->branch_id
            );

            if (!$managerShift->canStart()) {
                return $this->errorResponse('Cannot start this shift. Status: ' . $managerShift->status, 400);
            }

            $managerShift = $this->shiftService->startShift($managerShift);
            $progress = $this->shiftService->getShiftProgress($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'progress' => $progress,
                'message' => 'Your shift has started successfully',
            ], 'Shift started successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get handoffs received from cashiers - Section C
     */
    // public function getHandoffsReceived(Request $request): JsonResponse
    // {
    //     try {
    //         $manager = auth()->user();

    //         $validator = Validator::make($request->all(), [
    //             'shift_id' => 'required|exists:branch_manager_shifts,id',
    //         ]);

    //         if ($validator->fails()) {
    //             return $this->errorResponse($validator->errors()->first(), 422);
    //         }

    //         $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
    //             ->findOrFail($request->shift_id);

    //         $handoffs = $this->shiftService->getHandoffsReceived($managerShift);

    //         return $this->successResponse([
    //             'handoffs' => $handoffs,
    //             'summary' => [
    //                 'total_handoffs' => count($handoffs),
    //                 'pending' => collect($handoffs)->where('manager_approval_status', 'pending')->count(),
    //                 'approved' => collect($handoffs)->where('manager_approval_status', 'approved')->count(),
    //                 'rejected' => collect($handoffs)->where('manager_approval_status', 'rejected')->count(),
    //                 'rejected_final' => collect($handoffs)->where('manager_approval_status', 'rejected_final')->count(),
    //             ]
    //         ], 'Handoffs retrieved successfully');
    //     } catch (\Exception $e) {
    //         return $this->errorResponse($e->getMessage(), 500);
    //     }
    // }

    /**
     * Approve handoff from cashier - Section C
     */
    public function approveHandoff(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_id' => 'required|exists:cashier_shift_handovers,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $handover = CashierShiftHandover::with('cashierShift')
                ->findOrFail($request->handover_id);

            // Verify manager has access to this handover
            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->where('branch_id', $handover->cashierShift->branch_id)
                ->where('shift_date', $handover->cashierShift->shift_date)
                ->firstOrFail();

            $result = $this->shiftService->approveHandoff($handover, $manager->id);

            return $this->successResponse([
                'handover' => $result,
                'message' => 'Handoff approved successfully'
            ], 'Handoff approved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Reject handoff from cashier - Section C
     */
    public function rejectHandoff(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_id' => 'required|exists:cashier_shift_handovers,id',
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $handover = CashierShiftHandover::with('cashierShift')
                ->findOrFail($request->handover_id);

            // Verify manager has access to this handover
            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->where('branch_id', $handover->cashierShift->branch_id)
                ->where('shift_date', $handover->cashierShift->shift_date)
                ->firstOrFail();

            $result = $this->shiftService->rejectHandoff(
                $handover,
                $manager->id,
                $request->rejection_reason
            );

            return $this->successResponse([
                'handover' => $result,
                'rejection_count' => $result['rejection_count'],
                'is_final_rejection' => $result['is_final_rejection'],
                'message' => $result['is_final_rejection']
                    ? 'Handoff rejected permanently (2nd rejection)'
                    : 'Handoff rejected. Cashier can edit and resubmit.'
            ], 'Handoff rejected successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * End shift - FIXED
     */
    public function endShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'sometimes|exists:branch_manager_shifts,id',
            'next_manager_id' => 'nullable|exists:branch_managers,id',
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            'handover_timing' => 'nullable|in:today,yesterday',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            // Get shift - either from request or today's shift
            if ($request->has('shift_id')) {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->findOrFail($request->shift_id);
            } else {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->where('shift_date', today())
                    ->firstOrFail();
            }

            if (!$managerShift->canEnd()) {
                return $this->errorResponse('Cannot end this shift. Current status: ' . $managerShift->status, 400);
            }

            // Check if all cashier handoffs are approved
            $pendingHandoffs = $this->shiftService->getPendingHandoffs($managerShift);
            if ($pendingHandoffs > 0) {
                return $this->errorResponse(
                    "You still have {$pendingHandoffs} pending cashier handoffs that need approval",
                    400
                );
            }

            // End shift
            $managerShift = $this->shiftService->endShift($managerShift);

            // If handover details provided, record it
            if ($request->filled('next_manager_id') && $request->filled('handover_amount')) {
                $managerShift = $this->shiftService->recordManagerHandover(
                    $managerShift,
                    $request->next_manager_id,
                    $request->handover_amount,
                    $request->handover_notes,
                    $request->handover_timing ?? 'today'
                );
            }

            $summary = $this->shiftService->getShiftSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'summary' => $summary,
                'handover_details' => $managerShift->handed_over_at ? [
                    'handover_from' => $manager->name,
                    'handover_to' => $managerShift->nextManager?->name,
                    'handover_amount' => (float) $managerShift->closing_balance,
                    'handover_date' => $managerShift->handed_over_at->format('Y-m-d'),
                    'handover_time' => $managerShift->handed_over_at->format('H:i'),
                    'timing' => $managerShift->handed_over_at->isToday() ? 'today' : 'yesterday',
                    'status' => 'completed',
                ] : null,
                'next_actions' => [
                    'record_handover' => !$managerShift->handed_over_at,
                    'view_final_daily_close' => true,
                    'submit_daily_report' => true,
                ],
            ], 'Shift ended successfully. Please review Final Daily Close.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not accessible', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Record manager handover (separate endpoint) - Section D
     */
    public function recordManagerHandover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
            'next_manager_id' => 'required|exists:branch_managers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            'handover_timing' => 'required|in:today,yesterday',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->findOrFail($request->shift_id);

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            if ($managerShift->handed_over_at) {
                return $this->errorResponse('Handover already recorded', 400);
            }

            $managerShift = $this->shiftService->recordManagerHandover(
                $managerShift,
                $request->next_manager_id,
                $request->handover_amount,
                $request->handover_notes,
                $request->handover_timing
            );

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'handover_details' => [
                    'handover_from' => $manager->name,
                    'handover_to' => $managerShift->nextManager->name,
                    'handover_amount' => (float) $request->handover_amount,
                    'handover_date' => $managerShift->handed_over_at->format('Y-m-d'),
                    'handover_time' => $managerShift->handed_over_at->format('H:i'),
                    'timing' => $request->handover_timing,
                    'status' => 'completed',
                    'variance' => (float) $managerShift->variance,
                    'notes' => $request->handover_notes,
                ],
            ], 'Handover recorded successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get final daily close summary - FIXED to auto-get current shift
     */
    public function getFinalDailyClose(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Option 1: Use shift_id from request if provided
            if ($request->has('shift_id')) {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->with([
                        'branch',
                        'branchManager',
                        'cashierShifts.cashier',
                        'cashierShifts.shift',
                        'cashierShifts.handoverStatus',
                        'cashierShifts.salesBreakdown.aggregator'
                    ])
                    ->findOrFail($request->shift_id);
            }
            // Option 2: Auto-get today's shift (for /my-shift/final-daily-close)
            else {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->where('shift_date', today())
                    ->with([
                        'branch',
                        'branchManager',
                        'cashierShifts.cashier',
                        'cashierShifts.shift',
                        'cashierShifts.handoverStatus',
                        'cashierShifts.salesBreakdown.aggregator'
                    ])
                    ->firstOrFail();
            }

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            $summary = $this->shiftService->getFinalDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'final_daily_close' => $summary,
                'can_edit' => !$managerShift->daily_report_submitted,
                'can_reopen' => $managerShift->can_reopen && $managerShift->daily_report_submitted,
                'next_actions' => [
                    'edit_closing_balance' => !$managerShift->daily_report_submitted,
                    'submit_daily_report' => !$managerShift->daily_report_submitted,
                    'reopen_shift' => $managerShift->can_reopen && $managerShift->daily_report_submitted,
                    'view_report' => $managerShift->daily_report_submitted,
                ]
            ], 'Final daily close summary retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('No shift found for today or shift not accessible', 404);
        } catch (\Exception $e) {
            Log::error('Final daily close error', [
                'manager_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve final daily close: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update final daily close - FIXED
     */
    public function updateFinalDailyClose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'sometimes|exists:branch_manager_shifts,id',
            'closing_balance' => 'sometimes|numeric|min:0',
            'expected_balance' => 'sometimes|numeric|min:0',
            'daily_report_notes' => 'nullable|string|max:1000',
            'cashier_adjustments' => 'sometimes|array',
            'cashier_adjustments.*.cashier_shift_id' => 'required|exists:cashier_shifts,id',
            'cashier_adjustments.*.closing_balance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            // Get shift - either from request or today's shift
            if ($request->has('shift_id')) {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->findOrFail($request->shift_id);
            } else {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->where('shift_date', today())
                    ->firstOrFail();
            }

            if ($managerShift->daily_report_submitted && !$managerShift->can_reopen) {
                return $this->errorResponse('Daily report already submitted and cannot be edited', 400);
            }

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            $managerShift = $this->shiftService->updateFinalDailyClose($managerShift, $request->all());
            $summary = $this->shiftService->getFinalDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'final_daily_close' => $summary,
                'adjustments_applied' => true,
                'message' => 'Daily close updated successfully'
            ], 'Daily close updated successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not accessible', 404);
        } catch (\Exception $e) {
            Log::error('Update final daily close error', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to update daily close: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit final daily report - FIXED
     */
    public function submitFinalDailyReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'sometimes|exists:branch_manager_shifts,id',
            'closing_balance' => 'sometimes|numeric|min:0',
            'expected_balance' => 'sometimes|numeric|min:0',
            'final_notes' => 'nullable|string|max:1000',
            'cashier_adjustments' => 'sometimes|array',
            'cashier_adjustments.*.cashier_shift_id' => 'required|exists:cashier_shifts,id',
            'cashier_adjustments.*.closing_balance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            // Get shift - either from request or today's shift
            if ($request->has('shift_id')) {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->findOrFail($request->shift_id);
            } else {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->where('shift_date', today())
                    ->firstOrFail();
            }

            if ($managerShift->daily_report_submitted && !$managerShift->can_reopen) {
                return $this->errorResponse('Daily report already submitted', 400);
            }

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            $managerShift = $this->shiftService->submitFinalDailyReport($managerShift, $request->all());
            $summary = $this->shiftService->getFinalDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'final_daily_close' => $summary,
                'submission_details' => [
                    'submitted_at' => $managerShift->daily_report_submitted_at->format('Y-m-d H:i:s'),
                    'submitted_by' => $manager->name,
                    'final_closing_balance' => (float) $managerShift->closing_balance,
                    'final_variance' => (float) $managerShift->variance,
                    'notes' => $managerShift->daily_report_notes,
                ],
                'message' => 'Daily report submitted successfully. Waiting for Sales Team approval.'
            ], 'Daily report submitted successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not accessible', 404);
        } catch (\Exception $e) {
            Log::error('Submit final daily report error', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to submit daily report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reopen shift - FIXED
     */
    public function reopenShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'sometimes|exists:branch_manager_shifts,id',
            'reopen_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            // Get shift - either from request or today's shift
            if ($request->has('shift_id')) {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->findOrFail($request->shift_id);
            } else {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->where('shift_date', today())
                    ->firstOrFail();
            }

            if (!$managerShift->can_reopen) {
                return $this->errorResponse('This shift cannot be reopened', 400);
            }

            if (!$managerShift->daily_report_submitted) {
                return $this->errorResponse('Shift must be submitted first', 400);
            }

            if (!$managerShift->shift_date->isToday()) {
                return $this->errorResponse('Can only reopen shift on the same day', 400);
            }

            $managerShift = $this->shiftService->reopenShift($managerShift, $request->reopen_reason);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'message' => 'Shift reopened successfully. You can now make changes and resubmit.',
                'reopened_at' => $managerShift->reopened_at->format('Y-m-d H:i:s')
            ], 'Shift reopened successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not accessible', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get handoffs received - FIXED
     */
    public function getHandoffsReceived(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get shift - either from request or today's shift
            if ($request->has('shift_id')) {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->findOrFail($request->shift_id);
            } else {
                $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                    ->where('shift_date', today())
                    ->firstOrFail();
            }

            $handoffs = $this->shiftService->getHandoffsReceived($managerShift);

            return $this->successResponse([
                'handoffs' => $handoffs,
                'summary' => [
                    'total_handoffs' => count($handoffs),
                    'pending' => collect($handoffs)->where('manager_approval_status', 'pending')->count(),
                    'approved' => collect($handoffs)->where('manager_approval_status', 'approved')->count(),
                    'rejected' => collect($handoffs)->where('manager_approval_status', 'rejected')->count(),
                    'rejected_final' => collect($handoffs)->where('manager_approval_status', 'rejected_final')->count(),
                ]
            ], 'Handoffs retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found or not accessible', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get shift statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $dateFrom = $request->input('date_from', now()->subMonth()->format('Y-m-d'));
            $dateTo = $request->input('date_to', now()->format('Y-m-d'));

            $shifts = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereBetween('shift_date', [$dateFrom, $dateTo])
                ->get();

            $totalShifts = $shifts->count();
            $completedShifts = $shifts->where('status', 'completed')->count();
            $totalSales = $shifts->sum('total_sales');
            $totalVariance = $shifts->sum('variance');

            return $this->successResponse([
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo,
                ],
                'overview' => [
                    'total_shifts' => $totalShifts,
                    'completed_shifts' => $completedShifts,
                    'completion_rate' => $totalShifts > 0
                        ? round(($completedShifts / $totalShifts) * 100, 2)
                        : 0,
                ],
                'financial' => [
                    'total_sales' => (float) $totalSales,
                    'average_sales_per_shift' => $completedShifts > 0
                        ? round($totalSales / $completedShifts, 2)
                        : 0,
                    'total_variance' => (float) $totalVariance,
                    'average_variance' => $completedShifts > 0
                        ? round($totalVariance / $completedShifts, 2)
                        : 0,
                ],
                'cashier_shifts' => [
                    'total_managed' => $shifts->sum('total_cashier_shifts'),
                    'completed' => $shifts->sum('completed_cashier_shifts'),
                    'pending' => $shifts->sum('pending_cashier_shifts'),
                ],
            ], 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }


    
}
