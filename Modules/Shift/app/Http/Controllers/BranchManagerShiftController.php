<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Transformers\BranchManagerShiftResource;

class BranchManagerShiftController extends BaseController
{
    public function __construct(
        private BranchManagerShiftService $shiftService
    ) {}

    /**
     * Get current shift (today's shift)
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

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            // Filter by date range
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
     * Get shift details
     */
    public function show(string $id): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->with(['branch', 'nextManager'])
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
     * Start shift
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
                return $this->errorResponse('Cannot start this shift', 400);
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
     * End shift only (without handover)
     */
    public function endOnly(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->findOrFail($request->shift_id);

            if (!$managerShift->canEnd()) {
                return $this->errorResponse('Cannot end this shift', 400);
            }

            // Check if all cashier shifts are completed
            if ($managerShift->pending_cashier_shifts > 0) {
                return $this->errorResponse(
                    "You still have {$managerShift->pending_cashier_shifts} pending cashier shifts",
                    400
                );
            }

            $managerShift = $this->shiftService->endShift($managerShift);
            $summary = $this->shiftService->getShiftSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'summary' => $summary,
                'next_actions' => [
                    'handover_cash_now' => true,
                    'view_details' => true,
                ],
            ], 'Shift ended successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * End shift with handover
     */
    public function endWithHandover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
            'next_manager_id' => 'required|exists:branch_managers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->findOrFail($request->shift_id);

            if (!$managerShift->canEnd()) {
                return $this->errorResponse('Cannot end this shift', 400);
            }

            // Check if all cashier shifts are completed
            if ($managerShift->pending_cashier_shifts > 0) {
                return $this->errorResponse(
                    "You still have {$managerShift->pending_cashier_shifts} pending cashier shifts",
                    400
                );
            }

            // End shift
            $managerShift = $this->shiftService->endShift($managerShift);

            // Record handover
            $managerShift = $this->shiftService->recordHandover(
                $managerShift,
                $request->next_manager_id,
                $request->handover_amount,
                $request->handover_notes
            );

            $summary = $this->shiftService->getShiftSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'summary' => $summary,
                'handover_details' => [
                    'handover_amount' => (float) $request->handover_amount,
                    'variance' => (float) $managerShift->variance,
                    'variance_type' => $managerShift->variance > 0 ? 'Over' : ($managerShift->variance < 0 ? 'Short' : 'None'),
                    'handover_to' => $managerShift->nextManager->name,
                    'handover_notes' => $request->handover_notes,
                ],
            ], 'Shift ended with handover successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Record handover (after ending shift)
     */
    public function recordHandover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
            'next_manager_id' => 'required|exists:branch_managers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->findOrFail($request->shift_id);

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed before handover', 400);
            }

            if ($managerShift->handed_over_at) {
                return $this->errorResponse('Handover already recorded', 400);
            }

            $managerShift = $this->shiftService->recordHandover(
                $managerShift,
                $request->next_manager_id,
                $request->handover_amount,
                $request->handover_notes
            );

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'handover_details' => [
                    'handover_amount' => (float) $request->handover_amount,
                    'variance' => (float) $managerShift->variance,
                    'variance_type' => $managerShift->variance > 0 ? 'Over' : ($managerShift->variance < 0 ? 'Short' : 'None'),
                    'handover_to' => $managerShift->nextManager->name,
                    'handover_notes' => $request->handover_notes,
                ],
            ], 'Handover recorded successfully');
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


    /**
     * Get final daily close summary - معدل
     */
    public function getFinalDailyClose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->with(['branch', 'branchManager', 'cashierShifts.cashier', 'cashierShifts.shift', 'cashierShifts.handoverStatus', 'cashierShifts.salesBreakdown.aggregator'])
                ->find($request->shift_id); // استخدم find بدل findOrFail

            if (!$managerShift) {
                return $this->errorResponse('Shift not found or you do not have permission to access this shift', 404);
            }

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            $summary = $this->shiftService->getFinalDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'final_daily_close' => $summary,
                'can_edit' => !$managerShift->daily_report_submitted,
                'next_actions' => [
                    'edit_closing_balance' => !$managerShift->daily_report_submitted,
                    'submit_daily_report' => !$managerShift->daily_report_submitted,
                    'view_report' => $managerShift->daily_report_submitted,
                ]
            ], 'Final daily close summary retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Final daily close error', [
                'shift_id' => $request->shift_id,
                'manager_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve final daily close: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update final daily close with adjustments - معدل
     */
    public function updateFinalDailyClose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
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

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->find($request->shift_id);

            if (!$managerShift) {
                return $this->errorResponse('Shift not found or you do not have permission to access this shift', 404);
            }

            if ($managerShift->daily_report_submitted) {
                return $this->errorResponse('Daily report already submitted', 400);
            }

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            $managerShift = $this->shiftService->updateFinalDailyClose($managerShift, $request->all());

            // Get updated summary
            $summary = $this->shiftService->getFinalDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'final_daily_close' => $summary,
                'adjustments_applied' => true,
                'message' => 'Daily close updated successfully'
            ], 'Daily close updated successfully');
        } catch (\Exception $e) {
            \Log::error('Update final daily close error', [
                'shift_id' => $request->shift_id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to update daily close: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit final daily report - معدل
     */
    public function submitFinalDailyReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|exists:branch_manager_shifts,id',
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

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->find($request->shift_id);

            if (!$managerShift) {
                return $this->errorResponse('Shift not found or you do not have permission to access this shift', 404);
            }

            if ($managerShift->daily_report_submitted) {
                return $this->errorResponse('Daily report already submitted', 400);
            }

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            // Submit the final report
            $managerShift = $this->shiftService->submitFinalDailyReport($managerShift, $request->all());

            // Get final summary
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
                ]
            ], 'Daily report submitted successfully');
        } catch (\Exception $e) {
            Log::error('Submit final daily report error', [
                'shift_id' => $request->shift_id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to submit daily report: ' . $e->getMessage(), 500);
        }
    }
}
