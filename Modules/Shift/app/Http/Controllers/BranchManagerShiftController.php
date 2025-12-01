<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Section A: Start Shift
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if (!$managerShift->canStart()) {
                return $this->errorResponse('Cannot start shift. Current status: ' . $managerShift->status, 400);
            }

            $managerShift->update([
                'status' => 'in_progress',
                'actual_start_time' => now(),
            ]);

            $progress = $this->calculateShiftProgress($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'progress' => $progress,
                'message' => 'Shift started successfully'
            ], 'Shift started successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section B: Get Shift Details
     */
    public function getShiftDetails(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->with([
                    'branch',
                    'nextManager',
                    'approvedBy',
                    'cashierHandovers' => function ($query) {
                        $query->with(['cashierShift.cashier', 'cashierShift.shift']);
                    }
                ])
                ->when($request->has('shift_id'), function ($query) use ($request) {
                    return $query->where('id', $request->shift_id);
                }, function ($query) {
                    return $query->whereDate('shift_date', today());
                })
                ->firstOrFail();

            $details = [
                'assigned_to' => 'Me (Branch Manager)',
                'assigned_by' => 'Brand Owner',
                'store_branch' => $managerShift->branch->name,
                'start_time' => $managerShift->actual_start_time?->format('H:i'),
                'end_time' => $managerShift->actual_end_time?->format('H:i'),
                'final_approval_by' => $managerShift->approvedBy?->name ?? 'Pending',
                'status' => $managerShift->status,
                'shift_date' => $managerShift->shift_date->format('Y-m-d'),
            ];

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'details' => $details,
            ], 'Shift details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get Shift History
     */
    public function getShiftHistory(Request $request): JsonResponse
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
     * Section A: Shift Overview
     */
    public function current(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get or create today's shift
            $managerShift = BranchManagerShift::firstOrCreate(
                [
                    'branch_manager_id' => $manager->id,
                    'shift_date' => today(),
                ],
                [
                    'branch_id' => $manager->branch_id,
                    'status' => 'not_started',
                ]
            );

            // Load with relationships
            $managerShift->load([
                'branch',
                'nextManager',
                'cashierHandovers' => function ($query) {
                    $query->with([
                        'cashierShift.cashier',
                        'cashierShift.shift'
                    ]);
                }
            ]);

            // Calculate progress
            $progress = $this->calculateShiftProgress($managerShift);

            // Get handovers summary
            $handoversSummary = $managerShift->getHandoverSummary();

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'shift_progress' => $progress,
                'handovers_summary' => $handoversSummary,
                'can_start' => $managerShift->canStart(),
                'can_end' => $managerShift->canEnd(),
                'pending_handovers' => $handoversSummary['pending'],
            ], 'Current shift retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section C: Handoffs Received
     */
    public function getHandoffsReceived(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get today's shift with handovers
            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->with(['cashierHandovers' => function ($query) {
                    $query->with([
                        'cashierShift.cashier',
                        'cashierShift.shift',
                        'approvedBy'
                    ]);
                }])
                ->firstOrFail();

            // Transform handovers data
            $handoffs = $managerShift->cashierHandovers->map(function ($handover) {
                $cashierShift = $handover->cashierShift;
                $shift = $cashierShift->shift;

                return [
                    'handover_id' => $handover->id,
                    'cashier_shift_id' => $handover->cashier_shift_id,
                    'cashier_name' => $cashierShift->cashier->name,
                    'shift_time' => $shift ? $shift->name : 'N/A',
                    'handover_amount' => (float) $handover->handover_amount,
                    'total_sales' => (float) $cashierShift->total_sales,
                    'variance_amount' => (float) $handover->variance_amount,
                    'variance_type' => $handover->variance_amount > 0 ? 'Over' : ($handover->variance_amount < 0 ? 'Short' : 'None'),
                    'variance_reason' => $handover->variance_reason,
                    'attached_files' => $handover->variance_files ?? [],
                    'status' => $handover->status,
                    'rejection_reason' => $handover->rejection_reason,
                    'rejection_count' => $handover->rejection_count,
                    'handed_over_at' => $handover->handed_over_at?->format('Y-m-d H:i:s'),
                    'approved_at' => $handover->approved_at?->format('Y-m-d H:i:s'),
                    'approved_by' => $handover->approvedBy?->name,
                    'can_approve' => $handover->canApprove(),
                    'can_reject' => $handover->canReject(),
                ];
            });

            $summary = $managerShift->getHandoverSummary();

            return $this->successResponse([
                'handoffs' => $handoffs,
                'summary' => $summary,
                'can_end_shift' => $managerShift->canEnd(),
            ], 'Handoffs retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section C: Approve Handoff
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

            $handover = CashierShiftHandover::with('handoverTo')->findOrFail($request->handover_id);

            // Verify this handover is for current manager
            if (!$handover->handoverTo || $handover->handover_to_id !== $manager->id) {
                return $this->errorResponse('Unauthorized to approve this handover', 403);
            }

            if (!$handover->canApprove()) {
                return $this->errorResponse('Handover cannot be approved. Current status: ' . $handover->status, 400);
            }

            $handover->update([
                'status' => 'approved',
                'approved_by_id' => $manager->id,
                'approved_by_type' => 'branch_manager',
                'approved_at' => now(),
            ]);

            return $this->successResponse([
                'handover' => $handover,
                'message' => 'Handoff approved successfully'
            ], 'Handoff approved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section C: Reject Handoff
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

            $handover = CashierShiftHandover::with('handoverTo')->findOrFail($request->handover_id);

            // Verify this handover is for current manager
            if (!$handover->handoverTo || $handover->handover_to_id !== $manager->id) {
                return $this->errorResponse('Unauthorized to reject this handover', 403);
            }

            if (!$handover->canReject()) {
                return $this->errorResponse('Handover cannot be rejected. Current status: ' . $handover->status, 400);
            }

            $rejectionCount = $handover->rejection_count + 1;
            $isFinalRejection = $rejectionCount >= 2;

            $updateData = [
                'status' => $isFinalRejection ? 'rejected_final' : 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'rejection_count' => $rejectionCount,
            ];

            if ($rejectionCount === 1) {
                $updateData['first_rejected_at'] = now();
            } elseif ($rejectionCount === 2) {
                $updateData['second_rejected_at'] = now();
            }

            $handover->update($updateData);

            return $this->successResponse([
                'handover' => $handover,
                'rejection_count' => $rejectionCount,
                'is_final_rejection' => $isFinalRejection,
                'message' => $isFinalRejection
                    ? 'Handoff rejected permanently (2nd rejection)'
                    : 'Handoff rejected. Cashier can edit and resubmit.'
            ], 'Handoff rejected successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Helper method to calculate shift progress
     */
    private function calculateShiftProgress(BranchManagerShift $shift): array
    {
        $progress = [
            'title' => "Branch Manager Shift - " . $shift->shift_date->format('d M Y'),
            'description' => "Managing daily operations and cashier handovers",
            'status' => $shift->status,
            'start_time' => $shift->actual_start_time?->format('H:i'),
            'end_time' => $shift->actual_end_time?->format('H:i'),
            'elapsed_hours' => 0,
            'progress_percentage' => 0,
        ];

        if ($shift->status === 'in_progress' && $shift->actual_start_time) {
            $totalMinutes = 8 * 60; // 8 hours
            $elapsedMinutes = now()->diffInMinutes($shift->actual_start_time);
            $progress['elapsed_hours'] = round($elapsedMinutes / 60, 1);
            $progress['progress_percentage'] = min(($elapsedMinutes / $totalMinutes) * 100, 100);
        }

        return $progress;
    }

    /**
     * Section D: Final Handover and End Shift
     */
    public function endShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'required|exists:branch_managers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_timing' => 'required|in:today,yesterday',
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if (!$managerShift->canEnd()) {
                return $this->errorResponse('Cannot end shift. Check all cashier handoffs are approved.', 400);
            }

            // Calculate financial summary from cashier shifts
            $financialSummary = $this->calculateFinancialSummary($managerShift);

            // Set handover time based on timing
            $handoverTime = $request->handover_timing === 'yesterday'
                ? now()->subDay()
                : now();

            // Update shift with handover details
            $managerShift->update([
                'status' => 'completed',
                'actual_end_time' => now(),
                'next_manager_id' => $request->handover_to,
                'handover_amount' => $request->handover_amount,
                'handover_date' => $handoverTime->format('Y-m-d'),
                'handover_time' => $handoverTime,
                'handover_timing' => $request->handover_timing,
                'handover_status' => 'pending',
                'handover_notes' => $request->handover_notes,
                'closing_balance' => $request->handover_amount,
                ...$financialSummary,
            ]);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'handover_details' => [
                    'handover_amount' => (float) $request->handover_amount,
                    'handover_from' => $manager->name,
                    'handover_to' => $managerShift->nextManager->name,
                    'handover_date' => $managerShift->handover_date,
                    'handover_time' => $managerShift->handover_time->format('H:i'),
                    'handover_timing' => $managerShift->handover_timing,
                    'status' => $managerShift->handover_status,
                    'notes' => $managerShift->handover_notes,
                ],
                'message' => 'Shift ended and handover recorded successfully'
            ], 'Shift ended successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section E: Final Daily Close
     */
    public function getFinalDailyClose(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->with([
                    'cashierHandovers.cashierShift.cashier',
                    'cashierHandovers.cashierShift.salesBreakdown'
                ])
                ->when($request->has('shift_id'), function ($query) use ($request) {
                    return $query->where('id', $request->shift_id);
                }, function ($query) {
                    return $query->whereDate('shift_date', today());
                })
                ->firstOrFail();

            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed first', 400);
            }

            $dailyClose = $this->prepareDailyCloseSummary($managerShift);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'daily_close_summary' => $dailyClose,
                'can_submit' => !$managerShift->daily_report_submitted,
                'can_reopen' => $managerShift->can_reopen && $managerShift->daily_report_submitted,
            ], 'Final daily close summary retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section E: Submit Daily Report
     */
    public function submitDailyReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'final_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->where('status', 'completed')
                ->firstOrFail();

            if ($managerShift->daily_report_submitted) {
                return $this->errorResponse('Daily report already submitted', 400);
            }

            $managerShift->update([
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
                'daily_report_notes' => $request->final_notes,
                'can_reopen' => true, // Allow reopening on same day
            ]);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'message' => 'Daily report submitted successfully. Waiting for Sales Team approval.'
            ], 'Daily report submitted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section E: Reopen Shift
     */
    public function reopenShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reopen_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            if (!$managerShift->can_reopen) {
                return $this->errorResponse('This shift cannot be reopened', 400);
            }

            if (!$managerShift->daily_report_submitted) {
                return $this->errorResponse('Shift must be submitted first', 400);
            }

            $managerShift->update([
                'daily_report_submitted' => false,
                'daily_report_submitted_at' => null,
                'reopened_at' => now(),
                'reopen_reason' => $request->reopen_reason,
                'can_reopen' => false, // Prevent multiple reopens
            ]);

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'message' => 'Shift reopened successfully. You can now make changes and resubmit.'
            ], 'Shift reopened successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // Helper Methods


    private function calculateFinancialSummary(BranchManagerShift $shift): array
    {
        $cashierShifts = $shift->cashierHandovers()
            ->where('status', 'approved')
            ->with('cashierShift')
            ->get();

        $summary = [
            'total_sales' => 0,
            'cash_collected' => 0,
            'card_payments' => 0,
            'delivery_app_payments' => 0,
        ];

        foreach ($cashierShifts as $handover) {
            $cashierShift = $handover->cashierShift;
            $summary['total_sales'] += $cashierShift->total_sales;
            $summary['cash_collected'] += $cashierShift->cash_collected;
            $summary['card_payments'] += $cashierShift->card_payments;
            $summary['delivery_app_payments'] += $cashierShift->aggregator_payments;
        }

        return $summary;
    }

    private function prepareDailyCloseSummary(BranchManagerShift $shift): array
    {
        $cashierHandovers = $shift->cashierHandovers()
            ->where('status', 'approved')
            ->with(['cashierShift.cashier', 'cashierShift.salesBreakdown'])
            ->get();

        $cashierBreakdown = [];
        $totals = [
            'cash_collected' => 0,
            'card_payments' => 0,
            'delivery_app_payments' => 0,
            'variance' => 0,
            'sales' => 0,
        ];

        foreach ($cashierHandovers as $handover) {
            $cashierShift = $handover->cashierShift;

            $deliveryApps = $cashierShift->salesBreakdown
                ->where('type', 'delivery_app')
                ->sum('amount');

            $breakdown = [
                'cashier_name' => $cashierShift->cashier->name,
                'cash_collected' => (float) $cashierShift->cash_collected,
                'card_payments' => (float) $cashierShift->card_payments,
                'delivery_app_payments' => (float) $deliveryApps,
                'variance' => (float) $handover->variance_amount,
                'sales' => (float) $cashierShift->total_sales,
            ];

            $cashierBreakdown[] = $breakdown;

            // Update totals
            $totals['cash_collected'] += $breakdown['cash_collected'];
            $totals['card_payments'] += $breakdown['card_payments'];
            $totals['delivery_app_payments'] += $breakdown['delivery_app_payments'];
            $totals['variance'] += $breakdown['variance'];
            $totals['sales'] += $breakdown['sales'];
        }

        return [
            'cashier_breakdown' => $cashierBreakdown,
            'totals' => $totals,
            'manager_summary' => [
                'opening_balance' => (float) $shift->opening_balance,
                'closing_balance' => (float) $shift->closing_balance,
                'expected_balance' => (float) $shift->expected_balance,
                'variance' => (float) $shift->variance,
                'variance_type' => $shift->variance > 0 ? 'Over' : ($shift->variance < 0 ? 'Short' : 'None'),
            ],
            'shift_info' => [
                'date' => $shift->shift_date->format('Y-m-d'),
                'manager' => $shift->branchManager->name,
                'branch' => $shift->branch->name,
            ],
        ];
    }
}
