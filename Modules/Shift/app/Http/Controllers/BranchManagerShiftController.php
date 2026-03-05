<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftSalesBreakdown;
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
     * OPTIMIZED: Select only required fields to reduce query size
     */
    public function getShiftDetails(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->select([
                    'id',
                    'branch_manager_id',
                    'branch_id',
                    'shift_date',
                    'status',
                    'actual_start_time',
                    'actual_end_time',
                    'next_manager_id',
                    'approved_by_id',
                    'approved_by_type'
                ])
                ->with([
                    'branch:id,name',
                    'nextManager:id,name',
                    'approvedBy:id,name',
                    'cashierHandovers' => function ($query) {
                        $query->select(['id', 'cashier_shift_id', 'handover_to_id', 'status'])
                            ->with([
                                'cashierShift:id,cashier_id,shift_id',
                                'cashierShift.cashier:id,name',
                                'cashierShift.shift:id,name'
                            ]);
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
     * OPTIMIZED: Select only required fields and extract filters to helper method
     */
    public function getShiftHistory(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $query = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->select([
                    'id',
                    'branch_manager_id',
                    'branch_id',
                    'shift_date',
                    'status',
                    'actual_start_time',
                    'actual_end_time',
                    'next_manager_id',
                    'created_at',
                    'updated_at'
                ])
                ->with([
                    'branch:id,name,location',
                    'nextManager:id,name'
                ])
                ->orderBy('shift_date', 'desc');

            $this->applyShiftFilters($query, $request);

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
     * OPTIMIZED: Using Service methods to reduce queries and code duplication
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
                    'opening_balance' => $this->resolveBranchManagerOpeningBalance($manager->branch_id),
                ]
            );

            // Load only necessary relationships (optimized)
            $managerShift->load(['branch:id,name', 'nextManager:id,name']);

            // Calculate progress
            $progress = $this->calculateShiftProgress($managerShift);

            // Get handovers summary
            $handoversSummary = $managerShift->getHandoverSummary();

            // Get handovers fresh (skip cache) so approve/reject status reflects immediately in workday/current
            $handoversToManager = $this->shiftService->getShiftHandovers($managerShift, 'to_manager', true);

            // Transform handovers using reusable Service method (maintains exact response format)
            $handoffsToManager = $handoversToManager->map(function ($handover) {
                return $this->shiftService->transformHandover($handover);
            });

            // Section A: Shift Overview Response (EXACT SAME FORMAT)
            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                'shift_progress' => [
                    'title' => $progress['title'],
                    'description' => $progress['description'],
                    'status' => $progress['status'],
                    'start_time' => $progress['start_time'],
                    'end_time' => $progress['end_time'],
                    'number_of_hours' => $progress['elapsed_hours'],
                    'progress_percentage' => $progress['progress_percentage'],
                ],
                'handovers_summary' => $handoversSummary,
                'handovers_details' => [
                    'to_branch_manager' => $handoffsToManager,
                    // 'between_cashiers' => $cashierToCashierHandovers,
                ],
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
     * الحصول على جميع handovers من الكاشيرز للبرانش مانجر
     * OPTIMIZED: Using Service methods to reduce queries and code duplication
     */
    public function getHandoffsReceived(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get today's shift
            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            // Get handovers using optimized Service method (with caching)
            $handoversToManager = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
            $cashierToCashierHandovers = $this->shiftService->getShiftHandovers($managerShift, 'between_cashiers');

            // Transform handovers using reusable Service method (maintains exact response format)
            $handoffsToManager = $handoversToManager->map(function ($handover) {
                return $this->shiftService->transformHandover($handover);
            });

            $cashierToCashierHandoversTransformed = $cashierToCashierHandovers->map(function ($handover) {
                return $this->shiftService->transformHandover($handover);
            });

            $summary = $managerShift->getHandoverSummary();

            return $this->successResponse([
                'handoffs' => [
                    'to_branch_manager' => $handoffsToManager,
                    'between_cashiers' => $cashierToCashierHandoversTransformed,
                ],
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

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
                $handover->update([
                    'status' => 'approved',
                    'approved_by_id' => $manager->id,
                    'approved_by_type' => 'branch_manager',
                    'approved_at' => now(),
                ]);

                // Fire event for personal ledger transaction creation
                if ($handover->handover_to_type === 'branch_manager') {
                    event(new \Modules\Custody\Events\HandoverApproved($handover->fresh()));
                }

                // Normalize status for response
                $normalizedStatus = $this->normalizeHandoverStatus($handover->status);

                DB::commit();

                return $this->successResponse([
                    'handover' => array_merge($handover->toArray(), ['status' => $normalizedStatus]),
                    'message' => 'Handoff approved successfully'
                ], 'Handoff approved successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
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

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
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

                // Normalize status for response
                $normalizedStatus = $this->normalizeHandoverStatus($handover->status);

                DB::commit();

                return $this->successResponse([
                    'handover' => array_merge($handover->toArray(), ['status' => $normalizedStatus]),
                    'rejection_count' => $rejectionCount,
                    'is_final_rejection' => $isFinalRejection,
                    'message' => $isFinalRejection
                        ? 'Handoff rejected permanently (2nd rejection)'
                        : 'Handoff rejected. Cashier can edit and resubmit.'
                ], 'Handoff rejected successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get Rejection Details
     * View rejection details: cashier name, reason, uploaded files
     * OPTIMIZED: Select only required fields to reduce query size
     */
    public function getRejectionDetails(string $shift): JsonResponse
    {
        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::select(['id', 'cashier_id', 'shift_id'])
                ->with([
                    'cashier:id,name',
                    'handoverStatus:id,cashier_shift_id,rejection_reason,rejection_count,rejection_file_urls,first_rejected_at,second_rejected_at,manager_comment,reviewed_by_id,reviewed_by_type,reviewed_at',
                    'handoverStatus.reviewedBy:id,name',
                    'shift:id,name,branch_id'
                ])
                ->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized to view this rejection', 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (!$handoverStatus) {
                return $this->errorResponse('No handover found for this shift', 404);
            }

            if (!$handoverStatus->isManagerRejected()) {
                return $this->errorResponse('This handover is not rejected', 400);
            }

            return $this->successResponse([
                'rejection_details' => [
                    'cashier_name' => $shiftModel->cashier->name,
                    'cashier_id' => $shiftModel->cashier_id,
                    'shift_id' => $shiftModel->id,
                    'rejection_reason' => $handoverStatus->rejection_reason,
                    'rejection_count' => $handoverStatus->rejection_count,
                    'is_final_rejection' => $handoverStatus->isPermanentlyRejected(),
                    'rejection_files' => $handoverStatus->rejection_file_urls,
                    'first_rejected_at' => $handoverStatus->first_rejected_at?->format('Y-m-d H:i:s'),
                    'second_rejected_at' => $handoverStatus->second_rejected_at?->format('Y-m-d H:i:s'),
                    'manager_comment' => $handoverStatus->manager_comment,
                    'reviewed_by' => $handoverStatus->reviewedBy?->name,
                    'reviewed_at' => $handoverStatus->reviewed_at?->format('Y-m-d H:i:s'),
                ],
                'can_approve_rejection' => !$handoverStatus->isPermanentlyRejected(),
                'can_request_corrections' => $handoverStatus->rejection_count === 1,
            ], 'Rejection details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Process Rejection Decision
     * Approve rejection (make it final) or request corrections (add comment, allow cashier to edit)
     */
    public function processRejectionDecision(Request $request, string $shift): JsonResponse
    {
        // Get request data - prioritize JSON if available
        $jsonData = $request->json()->all();
        $requestData = !empty($jsonData) ? $jsonData : $request->all();

        $validator = Validator::make($requestData, [
            'decision' => 'nullable|in:approve_rejection,request_corrections',
            'manager_comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::select(['id', 'cashier_id', 'shift_id'])
                ->with([
                    'cashier:id,name',
                    'handoverStatus:id,cashier_shift_id,rejection_reason,rejection_count,manager_approval_status,first_rejected_at,second_rejected_at,manager_comment,reviewed_by_id,reviewed_by_type,reviewed_at',
                    'shift:id,name,branch_id'
                ])
                ->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('Unauthorized to process this rejection', 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (!$handoverStatus) {
                return $this->errorResponse('No handover found for this shift', 404);
            }

            if (!$handoverStatus->isManagerRejected()) {
                return $this->errorResponse('This handover is not rejected', 400);
            }

            if ($handoverStatus->isPermanentlyRejected()) {
                return $this->errorResponse('This rejection is already final and cannot be modified', 400);
            }

            $decision = $requestData['decision'] ?? $request->decision;
            $comment = $requestData['manager_comment'] ?? $request->manager_comment;

            if ($decision === 'approve_rejection') {
                // Approve rejection = make it final (2nd rejection)
                if ($handoverStatus->rejection_count >= 2) {
                    return $this->errorResponse('Rejection is already final', 400);
                }

                // Make it final rejection
                $handoverStatus->update([
                    'manager_approval_status' => 'rejected_final',
                    'rejection_count' => 2,
                    'second_rejected_at' => now(),
                    'manager_comment' => $comment ?? null,
                    'reviewed_by_id' => $manager->id,
                    'reviewed_by_type' => get_class($manager),
                    'reviewed_at' => now(),
                ]);

                // Update CashierShiftHandover status
                CashierShiftHandover::where('cashier_shift_id', $shiftModel->id)
                    ->update([
                        'status' => 'rejected_final',
                        'rejection_count' => 2,
                    ]);

                return $this->successResponse([
                    'decision' => 'approve_rejection',
                    'message' => 'Rejection approved and finalized. Cashier cannot edit anymore.',
                    'rejection_details' => [
                        'cashier_name' => $shiftModel->cashier->name,
                        'rejection_count' => 2,
                        'is_final_rejection' => true,
                        'manager_comment' => $comment ?? null,
                        'processed_at' => now()->format('Y-m-d H:i:s'),
                    ],
                ], 'Rejection approved successfully');
            } else {
                // Request corrections = add comment, keep as rejected (cashier can edit)
                $handoverStatus->update([
                    'manager_comment' => $comment ?? null,
                    'reviewed_by_id' => $manager->id,
                    'reviewed_by_type' => get_class($manager),
                    'reviewed_at' => now(),
                ]);

                return $this->successResponse([
                    'decision' => 'request_corrections',
                    'message' => 'Corrections requested. Cashier can edit and resubmit.',
                    'rejection_details' => [
                        'cashier_name' => $shiftModel->cashier->name,
                        'rejection_count' => $handoverStatus->rejection_count,
                        'is_final_rejection' => false,
                        'manager_comment' => $comment ?? null,
                        'can_cashier_edit' => true,
                        'processed_at' => now()->format('Y-m-d H:i:s'),
                    ],
                ], 'Corrections requested successfully');
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Resolve the opening balance for a new branch manager shift.
     * Uses the most recent previous shift's handover_amount (closing cash handed over),
     * falling back to 0 if no prior shift exists for this branch.
     */
    private function resolveBranchManagerOpeningBalance(string $branchId): float
    {
        $previousShift = BranchManagerShift::where('branch_id', $branchId)
            ->where('shift_date', '<', today())
            ->where('status', 'completed')
            ->orderByDesc('shift_date')
            ->select(['handover_amount', 'closing_balance'])
            ->first();

        if (!$previousShift) {
            return 0.0;
        }

        return (float) ($previousShift->handover_amount ?? $previousShift->closing_balance ?? 0);
    }

    /**
     * Helper method to calculate shift progress
     * OPTIMIZED: Reduce date operations and early returns
     */
    private function calculateShiftProgress(BranchManagerShift $shift): array
    {
        $defaultStartTime = '09:00';
        $defaultEndTime = '17:00';
        $defaultShiftHours = 8;

        $startTime = $shift->actual_start_time;
        $endTime = $shift->actual_end_time;
        $shiftDateFormatted = $shift->shift_date->format('d M Y');

        $progress = [
            'title' => "Branch Manager Shift - " . $shiftDateFormatted,
            'description' => "Managing daily operations and cashier handovers",
            'status' => $shift->status === 'not_started' ? 'Not Started' : ($shift->status === 'in_progress' ? 'In Progress' : 'Completed'),
            'start_time' => $startTime ? $startTime->format('H:i') : $defaultStartTime,
            'end_time' => $endTime ? $endTime->format('H:i') : $defaultEndTime,
            'elapsed_hours' => 0,
            'progress_percentage' => 0,
        ];

        if (!$startTime) {
            return $progress;
        }

        if ($shift->status === 'in_progress') {
            $expectedEndTime = $endTime ?: $startTime->copy()->addHours($defaultShiftHours);
            $totalMinutes = $startTime->diffInMinutes($expectedEndTime);
            $elapsedMinutes = now()->diffInMinutes($startTime);

            $progress['elapsed_hours'] = round($elapsedMinutes / 60, 2);
            $progress['progress_percentage'] = $totalMinutes > 0
                ? min(($elapsedMinutes / $totalMinutes) * 100, 100)
                : 0;
        } elseif ($shift->status === 'completed' && $endTime) {
            $progress['elapsed_hours'] = round($startTime->diffInHours($endTime), 2);
            $progress['progress_percentage'] = 100;
        }

        return $progress;
    }

    // /**
    //  * Section D: Final Handover and End Shift
    //  */
    // public function endShift(Request $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'handover_to' => 'required|exists:branch_managers,id',
    //         'handover_amount' => 'required|numeric|min:0',
    //         'handover_timing' => 'required|in:today,yesterday',
    //         'handover_notes' => 'nullable|string|max:500',
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->errorResponse($validator->errors()->first(), 422);
    //     }

    //     try {
    //         $manager = auth()->user();

    //         $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
    //             ->whereDate('shift_date', today())
    //             ->firstOrFail();

    //         if (!$managerShift->canEnd()) {
    //             return $this->errorResponse('Cannot end shift. Check all cashier handoffs are approved.', 400);
    //         }

    //         // Calculate financial summary from cashier shifts
    //         $financialSummary = $this->calculateFinancialSummary($managerShift);

    //         // Set handover time based on timing
    //         $handoverTime = $request->handover_timing === 'yesterday'
    //             ? now()->subDay()
    //             : now();

    //         // Update shift with handover details
    //         $managerShift->update([
    //             'status' => 'completed',
    //             'actual_end_time' => now(),
    //             'next_manager_id' => $request->handover_to,
    //             'handover_amount' => $request->handover_amount,
    //             'handover_date' => $handoverTime->format('Y-m-d'),
    //             'handover_time' => $handoverTime,
    //             'handover_timing' => $request->handover_timing,
    //             'handover_status' => 'pending',
    //             'handover_notes' => $request->handover_notes,
    //             'closing_balance' => $request->handover_amount,
    //             ...$financialSummary,
    //         ]);

    //         return $this->successResponse([
    //             'shift' => new BranchManagerShiftResource($managerShift),
    //             'handover_details' => [
    //                 'handover_amount' => (float) $request->handover_amount,
    //                 'handover_from' => $manager->name,
    //                 'handover_to' => $managerShift->nextManager->name,
    //                 'handover_date' => $managerShift->handover_date,
    //                 'handover_time' => $managerShift->handover_time->format('H:i'),
    //                 'handover_timing' => $managerShift->handover_timing,
    //                 'status' => $managerShift->handover_status,
    //                 'notes' => $managerShift->handover_notes,
    //             ],
    //             'message' => 'Shift ended and handover recorded successfully'
    //         ], 'Shift ended successfully');
    //     } catch (\Exception $e) {
    //         return $this->errorResponse($e->getMessage(), 500);
    //     }
    // }

    /**
     * Section D: Final Handover and End Shift
     * OPTIMIZED: Avoid redundant calculations and reuse handovers data
     */
    public function endShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'nullable|exists:branch_managers,id',
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_timing' => 'required|in:today,yesterday',
            'handover_notes' => 'nullable|string|max:500',
            // Financial values (optional - can be manually entered; total_sales may be negative e.g. refunds)
            'total_sales' => 'nullable|numeric',
            'cash_collected' => 'nullable|numeric|min:0',
            'card_payments' => 'nullable|numeric|min:0',
            'aggregator_payments' => 'nullable|numeric|min:0',
            // Cashier breakdown (optional - can update individual cashier shifts)
            'cashier_breakdown' => 'nullable|array',
            'cashier_breakdown.*.cashier_id' => 'required_with:cashier_breakdown|exists:cashiers,id',
            'cashier_breakdown.*.cash_collected' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.card_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.delivery_app_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.variance' => 'nullable|numeric',
            'cashier_breakdown.*.sales' => 'nullable|numeric',
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

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
                $financialSummary = null;
                $updatedHandovers = null;

                // If cashier_breakdown is provided, update using optimized bulk update method
                if ($request->has('cashier_breakdown') && is_array($request->cashier_breakdown)) {
                    $this->shiftService->bulkUpdateCashierShifts($request->cashier_breakdown, $managerShift);
                    // Calculate financial summary once after update
                    $financialSummary = $this->calculateFinancialSummary($managerShift);
                    // Get handovers once for both financial summary and response
                    $updatedHandovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
                } else {
                    // Calculate financial summary from existing data (with caching)
                    $financialSummary = $this->calculateFinancialSummary($managerShift);
                    // Get handovers once for response
                    $updatedHandovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
                }

                // Use provided financial values or calculate from cashier shifts
                $totalSales = $request->total_sales ?? $financialSummary['total_sales'] ?? 0;
                $cashCollected = $request->cash_collected ?? $financialSummary['cash_collected'] ?? 0;
                $cardPayments = $request->card_payments ?? $financialSummary['card_payments'] ?? 0;
                $aggregatorPayments = $request->aggregator_payments ?? $financialSummary['delivery_app_payments'] ?? 0;

                // Calculate VAT and Net Sales from total_sales
                $vatAmount = $totalSales * 0.15;
                $netSales = $totalSales - $vatAmount;

                // Calculate closing_balance from approved handovers if handover_amount is not provided
                $handoverAmount = $request->handover_amount;
                $closingBalance = $handoverAmount;

                if ($handoverAmount === null) {
                    // OPTIMIZED: Use subquery instead of whereHas for better performance
                    $cashierShiftIds = DB::table('cashier_shifts')
                        ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
                        ->whereDate('cashier_shifts.shift_date', $managerShift->shift_date)
                        ->where('shifts.branch_id', $managerShift->branch_id)
                        ->pluck('cashier_shifts.id');

                    $approvedHandovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
                        ->where('handover_to_id', $manager->id)
                        ->where('status', 'approved')
                        ->whereIn('cashier_shift_id', $cashierShiftIds)
                        ->sum('handover_amount');

                    $closingBalance = $approvedHandovers ?? ($managerShift->closing_balance ?? 0);
                    $handoverAmount = $closingBalance; // Use calculated value if not provided
                }

                // Set handover time based on timing
                $handoverTime = $request->handover_timing === 'yesterday'
                    ? now()->subDay()
                    : now();

                // Prepare update data - only update fields that are provided or need to be set
                $updateData = [
                    'status' => 'completed',
                    'actual_end_time' => now(),
                    'handover_date' => $handoverTime->format('Y-m-d'),
                    'handover_time' => $handoverTime,
                    'handover_timing' => $request->handover_timing,
                    'handover_status' => 'pending',
                    'handover_amount' => $handoverAmount, // Use provided value or calculated value
                    'closing_balance' => $closingBalance,
                    'total_sales' => $totalSales,
                    'net_sales' => $netSales,
                    'vat_amount' => $vatAmount,
                    'cash_collected' => $cashCollected,
                    'card_payments' => $cardPayments,
                    'aggregator_payments' => $aggregatorPayments,
                ];

                // Update next_manager_id if provided (nullable - can be set to null)
                if ($request->has('handover_to')) {
                    $updateData['next_manager_id'] = $request->handover_to;
                }

                // Update handover_notes if provided (nullable - can be set to null or keep existing)
                if ($request->has('handover_notes')) {
                    $updateData['handover_notes'] = $request->handover_notes;
                }

                // Update shift with handover details AND financial totals
                $managerShift->update($updateData);

                // Determine handover status: pending, accepted, rejected
                $handoverStatus = $this->normalizeHandoverStatus($managerShift->handover_status);

                // Get current time based on handover_timing
                $currentTime = $request->handover_timing === 'yesterday'
                    ? now()->subDay()->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');

                // Clear cache after update
                $this->shiftService->clearShiftCaches($managerShift);

                // Refresh the model to get updated relationships
                $managerShift->refresh();
                $managerShift->load('nextManager');

                // OPTIMIZED: Prepare cashier breakdown for response (reuse already fetched handovers)
                // Pre-calculate delivery apps totals using aggregate query to avoid N+1
                $cashierShiftIds = $updatedHandovers->pluck('cashier_shift_id')->toArray();
                $deliveryAppsTotals = ShiftSalesBreakdown::whereIn('cashier_shift_id', $cashierShiftIds)
                    ->selectRaw('cashier_shift_id, SUM(amount) as total_amount')
                    ->groupBy('cashier_shift_id')
                    ->pluck('total_amount', 'cashier_shift_id')
                    ->toArray();

                $cashierBreakdownResponse = [];
                foreach ($updatedHandovers as $handover) {
                    $cashierShift = $handover->cashierShift;
                    $deliveryApps = (float) ($deliveryAppsTotals[$cashierShift->id] ?? 0);

                    $cashierBreakdownResponse[] = [
                        'cashier_name' => $cashierShift->cashier->name,
                        'cashier_id' => $cashierShift->cashier_id,
                        'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                        'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                        'delivery_app_payments' => $deliveryApps,
                        'variance' => (float) ($handover->variance_amount ?? 0),
                        'sales' => (float) ($cashierShift->total_sales ?? 0),
                    ];
                }

                // Commit transaction
                DB::commit();

                return $this->successResponse([
                    'shift' => new BranchManagerShiftResource($managerShift),
                    // Section D: Final Handover and End Shift - Exact format as per requirements
                    'final_handover' => [
                        'handover_amount' => (float) ($managerShift->handover_amount ?? $closingBalance),
                        'status' => $handoverStatus, // pending, accepted, rejected
                        'status_options' => ['pending', 'accepted', 'rejected'],
                        'handover_from' => $manager->name,
                        'handover_to' => $managerShift->nextManager?->name ?? 'Not specified',
                        'handover_date' => $managerShift->handover_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
                        'handover_time' => $managerShift->handover_time?->format('H:i:s') ?? now()->format('H:i:s'),
                        'current_time' => $currentTime,
                        'current_time_setting' => $request->handover_timing, // 'today' or 'yesterday'
                        'handover_notes' => $managerShift->handover_notes,
                    ],
                    // Section E: Daily Totals (calculated across all cashiers or manually entered)
                    'daily_totals' => [
                        'total_cash_collected' => (float) $cashCollected,
                        'total_card_payments' => (float) $cardPayments,
                        'total_delivery_apps' => (float) $aggregatorPayments,
                        'total_variance' => (float) ($financialSummary['total_variance'] ?? 0),
                        'total_sales' => (float) $totalSales,
                        'shift_date' => $managerShift->shift_date->format('Y-m-d'),
                    ],
                    // Section E: Cashier Breakdown (per-cashier breakdown)
                    'cashier_breakdown' => $cashierBreakdownResponse,
                    'message' => 'Shift ended and handover recorded successfully'
                ], 'Shift ended successfully');
            } catch (\Exception $e) {
                // Rollback transaction on error
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section E: Final Daily Close
     * OPTIMIZED: Use Service method instead of loading unnecessary relationships
     */
    public function getFinalDailyClose(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->when($request->has('shift_id'), function ($query) use ($request) {
                    return $query->where('id', $request->shift_id);
                }, function ($query) {
                    return $query->whereDate('shift_date', today());
                })
                ->firstOrFail();

            // if ($managerShift->status !== 'completed') {
            //     return $this->errorResponse('Shift must be completed first', 400);
            // }

            $dailyClose = $this->prepareDailyCloseSummary($managerShift);

            // Section E: Final Daily Close - Exact format as per requirements
            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                // Per-cashier breakdown (before submission)
                'cashier_breakdown' => $dailyClose['cashier_breakdown'], // Each cashier: Cash Collected, Card Payments, Delivery App Payments, Variance, Sales
                // Totals (calculated across all cashiers)
                'totals' => $dailyClose['totals'], // Total Cash Collected, Total Card Payments, Total Delivery Apps, Total Variance, Total Sales
                // Submission status
                'daily_close_status' => [
                    'is_submitted' => (bool) $managerShift->daily_report_submitted,
                    'submitted_at' => $managerShift->daily_report_submitted_at?->format('Y-m-d H:i:s'),
                    'notes' => $managerShift->daily_report_notes,
                    'can_submit' => !$managerShift->daily_report_submitted,
                    'can_reopen' => $managerShift->can_reopen && $managerShift->daily_report_submitted,
                    'reopened_at' => $managerShift->reopened_at?->format('Y-m-d H:i:s'),
                    'reopen_reason' => $managerShift->reopen_reason,
                ],
                // Additional manager summary (optional)
                'manager_summary' => $dailyClose['manager_summary'] ?? null,
                'shift_info' => $dailyClose['shift_info'] ?? null,
            ], 'Final daily close summary retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Section E: Update Final Daily Close
     * تعديل Final Daily Close بعد إنهاء الـ shift
     * OPTIMIZED: Avoid redundant calculations similar to endShift
     */
    public function updateFinalDailyClose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'nullable|exists:branch_managers,id',
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            // Financial values (optional - can be manually entered; total_sales may be negative)
            'total_sales' => 'nullable|numeric',
            'cash_collected' => 'nullable|numeric|min:0',
            'card_payments' => 'nullable|numeric|min:0',
            'aggregator_payments' => 'nullable|numeric|min:0',
            // Cashier breakdown (optional - can update individual cashier shifts)
            'cashier_breakdown' => 'nullable|array',
            'cashier_breakdown.*.cashier_id' => 'required_with:cashier_breakdown|exists:cashiers,id',
            'cashier_breakdown.*.cash_collected' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.card_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.delivery_app_payments' => 'nullable|numeric|min:0',
            'cashier_breakdown.*.variance' => 'nullable|numeric',
            'cashier_breakdown.*.sales' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->firstOrFail();

            // Check if shift is completed (can be updated even if submitted, but not if archived)
            if ($managerShift->status !== 'completed') {
                return $this->errorResponse('Shift must be completed to update daily close', 400);
            }

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
                $financialSummary = null;
                $updatedHandovers = null;

                // If cashier_breakdown is provided, update using optimized bulk update method
                if ($request->has('cashier_breakdown') && is_array($request->cashier_breakdown)) {
                    $this->shiftService->bulkUpdateCashierShifts($request->cashier_breakdown, $managerShift);
                    // Calculate financial summary once after update
                    $financialSummary = $this->calculateFinancialSummary($managerShift);
                    // Get handovers once for both financial summary and response
                    $updatedHandovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
                } else {
                    // Calculate financial summary from existing data (with caching)
                    $financialSummary = $this->calculateFinancialSummary($managerShift);
                    // Get handovers once for response
                    $updatedHandovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');
                }

                // Use provided financial values or calculate from cashier shifts
                $totalSales = $request->total_sales ?? $financialSummary['total_sales'] ?? $managerShift->total_sales ?? 0;
                $cashCollected = $request->cash_collected ?? $financialSummary['cash_collected'] ?? $managerShift->cash_collected ?? 0;
                $cardPayments = $request->card_payments ?? $financialSummary['card_payments'] ?? $managerShift->card_payments ?? 0;
                $aggregatorPayments = $request->aggregator_payments ?? $financialSummary['delivery_app_payments'] ?? $managerShift->aggregator_payments ?? 0;

                // Calculate VAT and Net Sales from total_sales
                $vatAmount = $totalSales * 0.15;
                $netSales = $totalSales - $vatAmount;

                // Calculate closing_balance from approved handovers if handover_amount is not provided
                $handoverAmount = $request->handover_amount ?? $managerShift->handover_amount;

                if ($handoverAmount === null) {
                    // OPTIMIZED: Use subquery instead of whereHas for better performance
                    $cashierShiftIds = DB::table('cashier_shifts')
                        ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
                        ->whereDate('cashier_shifts.shift_date', $managerShift->shift_date)
                        ->where('shifts.branch_id', $managerShift->branch_id)
                        ->pluck('cashier_shifts.id');

                    $approvedHandovers = CashierShiftHandover::where('handover_to_type', 'branch_manager')
                        ->where('handover_to_id', $manager->id)
                        ->where('status', 'approved')
                        ->whereIn('cashier_shift_id', $cashierShiftIds)
                        ->sum('handover_amount');

                    $handoverAmount = $approvedHandovers ?? ($managerShift->closing_balance ?? 0);
                }

                $closingBalance = $handoverAmount;

                // Prepare update data
                $updateData = [
                    'handover_amount' => $handoverAmount,
                    'closing_balance' => $closingBalance,
                    'total_sales' => $totalSales,
                    'net_sales' => $netSales,
                    'vat_amount' => $vatAmount,
                    'cash_collected' => $cashCollected,
                    'card_payments' => $cardPayments,
                    'aggregator_payments' => $aggregatorPayments,
                ];

                // Update next_manager_id if provided (nullable - can be set to null)
                if ($request->has('handover_to')) {
                    $updateData['next_manager_id'] = $request->handover_to;
                }

                // Update handover_notes if provided (nullable - can be set to null or keep existing)
                if ($request->has('handover_notes')) {
                    $updateData['handover_notes'] = $request->handover_notes;
                }

                // Update shift with new data
                $managerShift->update($updateData);

                // Clear cache after update
                $this->shiftService->clearShiftCaches($managerShift);

                // Refresh the model to get updated relationships
                $managerShift->refresh();
                $managerShift->load('nextManager');

                // OPTIMIZED: Prepare cashier breakdown for response (reuse already fetched handovers)
                // Pre-calculate delivery apps totals using aggregate query to avoid N+1
                $cashierShiftIds = $updatedHandovers->pluck('cashier_shift_id')->toArray();
                $deliveryAppsTotals = ShiftSalesBreakdown::whereIn('cashier_shift_id', $cashierShiftIds)
                    ->selectRaw('cashier_shift_id, SUM(amount) as total_amount')
                    ->groupBy('cashier_shift_id')
                    ->pluck('total_amount', 'cashier_shift_id')
                    ->toArray();

                $cashierBreakdownResponse = [];
                foreach ($updatedHandovers as $handover) {
                    $cashierShift = $handover->cashierShift;
                    $deliveryApps = (float) ($deliveryAppsTotals[$cashierShift->id] ?? 0);

                    $cashierBreakdownResponse[] = [
                        'cashier_name' => $cashierShift->cashier->name,
                        'cashier_id' => $cashierShift->cashier_id,
                        'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                        'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                        'delivery_app_payments' => $deliveryApps,
                        'variance' => (float) ($handover->variance_amount ?? 0),
                        'sales' => (float) ($cashierShift->total_sales ?? 0),
                    ];
                }

                // Commit transaction
                DB::commit();

                return $this->successResponse([
                    'shift' => new BranchManagerShiftResource($managerShift),
                    // Section E: Cashier Breakdown (per-cashier breakdown)
                    'cashier_breakdown' => $cashierBreakdownResponse,
                    // Section E: Daily Totals (calculated across all cashiers or manually entered)
                    'daily_totals' => [
                        'total_cash_collected' => (float) $cashCollected,
                        'total_card_payments' => (float) $cardPayments,
                        'total_delivery_apps' => (float) $aggregatorPayments,
                        'total_variance' => (float) ($financialSummary['total_variance'] ?? 0),
                        'total_sales' => (float) $totalSales,
                        'shift_date' => $managerShift->shift_date->format('Y-m-d'),
                    ],
                    // Daily close status
                    'daily_close_status' => [
                        'is_submitted' => (bool) $managerShift->daily_report_submitted,
                        'submitted_at' => $managerShift->daily_report_submitted_at?->format('Y-m-d H:i:s'),
                        'notes' => $managerShift->daily_report_notes,
                        'can_submit' => !$managerShift->daily_report_submitted,
                        'can_reopen' => $managerShift->can_reopen && $managerShift->daily_report_submitted,
                        'reopened_at' => $managerShift->reopened_at?->format('Y-m-d H:i:s'),
                        'reopen_reason' => $managerShift->reopen_reason,
                    ],
                    'message' => 'Final daily close updated successfully'
                ], 'Final daily close updated successfully');
            } catch (\Exception $e) {
                // Rollback transaction on error
                DB::rollBack();
                throw $e;
            }
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

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
                $managerShift->update([
                    'daily_report_submitted' => true,
                    'daily_report_submitted_at' => now(),
                    'daily_report_notes' => $request->final_notes,
                    'can_reopen' => true, // Allow reopening on same day
                ]);

                DB::commit();

                return $this->successResponse([
                    'shift' => new BranchManagerShiftResource($managerShift),
                    'message' => 'Daily report submitted successfully. Waiting for Sales Team approval.'
                ], 'Daily report submitted successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
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

            // Use database transaction for critical operations
            DB::beginTransaction();

            try {
                $managerShift->update([
                    'daily_report_submitted' => false,
                    'daily_report_submitted_at' => null,
                    'reopened_at' => now(),
                    'reopen_reason' => $request->reopen_reason,
                    'can_reopen' => false, // Prevent multiple reopens
                ]);

                DB::commit();

                return $this->successResponse([
                    'shift' => new BranchManagerShiftResource($managerShift),
                    'message' => 'Shift reopened successfully. You can now make changes and resubmit.'
                ], 'Shift reopened successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get Cashier to Cashier Handover Details by ID
     * جلب تفاصيل handover بين الكاشيرز بالـ ID
     * OPTIMIZED: Select only required fields to reduce query size
     */
    public function getCashierHandoverDetails(string $handoverId): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get handover - support both cashier-to-cashier and cashier-to-manager handovers
            $handover = \Modules\Shift\Models\CashierShiftHandover::where('id', $handoverId)
                ->select([
                    'id',
                    'cashier_shift_id',
                    'handover_to_id',
                    'handover_to_type',
                    'handover_amount',
                    'variance_amount',
                    'variance_reason',
                    'variance_files',
                    'status',
                    'rejection_reason',
                    'rejection_count',
                    'handed_over_at',
                    'approved_at',
                    'approved_by_id',
                    'approved_by_type',
                    'handover_date',
                    'handover_time',
                    'handover_notes'
                ])
                ->with([
                    'cashierShift:id,cashier_id,shift_id,total_sales,net_sales,vat_amount,cash_collected,card_payments',
                    'cashierShift.cashier:id,name',
                    'cashierShift.shift:id,name,branch_id',
                    'cashierShift.salesBreakdown:id,cashier_shift_id,aggregator_id,amount',
                    'cashierShift.salesBreakdown.aggregator:id,name',
                    'cashierShift.varianceDetails:id,cashier_shift_id,responsible_cashier_id,assigned_amount,reason',
                    'cashierShift.varianceDetails.responsibleCashier:id,name',
                    'cashierShift.handoverStatus.reviewedBy:id,name',
                    'handoverTo:id,name',
                    'approvedBy:id,name'
                ])
                ->firstOrFail();

            $cashierShift = $handover->cashierShift;
            $shift = $cashierShift->shift;

            // Check access permissions based on handover type
            // For branch_manager handovers: must be handover to this manager
            // For cashier handovers: must be in manager's branch
            if ($handover->handover_to_type === 'branch_manager') {
                if ($handover->handover_to_id !== $manager->id) {
                    return $this->errorResponse('You do not have access to this handover', 403);
                }
            } elseif ($handover->handover_to_type === 'cashier') {
                // For cashier-to-cashier handovers, manager must have access to the branch
                if ($shift->branch_id !== $manager->branch_id) {
                    return $this->errorResponse('You do not have access to this handover', 403);
                }
            } else {
                return $this->errorResponse('Invalid handover type', 403);
            }

            // Prepare variance details
            $varianceDetails = null;
            if ($handover->variance_amount != 0) {
                $varianceDetails = [
                    'total_sales' => (float) $cashierShift->total_sales,
                    'handover_amount' => (float) $handover->handover_amount,
                    'variance_amount' => (float) $handover->variance_amount,
                    'variance_type' => $handover->variance_amount > 0 ? 'Over' : 'Short',
                    'reason_for_variance' => $handover->variance_reason,
                    'attached_files' => $handover->variance_files ?? [],
                    'cashier_details' => [
                        'id' => $cashierShift->cashier_id,
                        'name' => $cashierShift->cashier->name,
                        'variance_reason' => $handover->variance_reason,
                    ],
                ];

                if ($cashierShift->varianceDetails) {
                    $varianceDetails['other_cashiers'] = $cashierShift->varianceDetails->map(function ($detail) {
                        return [
                            'cashier_id' => $detail->responsible_cashier_id,
                            'cashier_name' => $detail->responsibleCashier?->name ?? 'External Factors',
                            'amount' => (float) $detail->assigned_amount,
                            'notes' => $detail->reason,
                        ];
                    })->toArray();
                }
            }

            // Calculate delivery app payments
            $deliveryApps = $cashierShift->salesBreakdown->sum('amount');

            return $this->successResponse([
                'handover' => [
                    'handover_id' => $handover->id,
                    'cashier_shift_id' => $handover->cashier_shift_id,
                    'cashier_name' => $cashierShift->cashier->name,
                    'cashier_id' => $cashierShift->cashier_id,
                    'shift_time' => $shift ? $shift->name : 'N/A',
                    'shift_id' => $shift ? $shift->id : null,
                    'handover_amount' => (float) $handover->handover_amount,
                    'total_sales' => (float) $cashierShift->total_sales,
                    'net_sales' => (float) ($cashierShift->net_sales ?? 0),
                    'vat_amount' => (float) ($cashierShift->vat_amount ?? 0),
                    'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                    'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                    'delivery_app_payments' => (float) $deliveryApps,
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
                    'approved_by_id' => $handover->approved_by_id,
                    'can_approve' => $handover->canApprove(),
                    'can_reject' => $handover->canReject(),
                    'variance_details' => $varianceDetails,
                    'handover_to_type' => $handover->handover_to_type,
                    'handover_to' => $handover->handoverTo?->name ?? 'N/A',
                    'handover_to_id' => $handover->handover_to_id,
                    'handover_date' => $handover->handover_date?->format('Y-m-d'),
                    'handover_time' => $handover->handover_time?->format('H:i:s'),
                    'handover_notes' => $handover->handover_notes,
                    'correction_details' => $cashierShift->handoverStatus ? $this->getCorrectionDetails($cashierShift->handoverStatus) : null,
                ],
            ], 'Cashier handover details retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Handover not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get Branch Manager Final Handover Details by Shift ID
     * جلب تفاصيل handover النهائي للبرانش مانجر بالـ ID
     * OPTIMIZED: Select only required fields to reduce query size
     */
    public function getManagerFinalHandover(string $shiftId): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('id', $shiftId)
                ->where('branch_manager_id', $manager->id)
                ->select([
                    'id',
                    'branch_manager_id',
                    'branch_id',
                    'shift_date',
                    'status',
                    'total_sales',
                    'net_sales',
                    'vat_amount',
                    'cash_collected',
                    'card_payments',
                    'aggregator_payments',
                    'handover_amount',
                    'closing_balance',
                    'opening_balance',
                    'handover_status',
                    'handover_timing',
                    'handover_date',
                    'handover_time',
                    'handover_notes',
                    'next_manager_id'
                ])
                ->with([
                    'branchManager:id,name',
                    'nextManager:id,name',
                    'branch:id,name'
                ])
                ->firstOrFail();

            // Calculate financial summary (using optimized Service method)
            $financialSummary = $this->calculateFinancialSummary($managerShift);

            // Get all handovers for this manager shift (using optimized Service method)
            $handovers = $this->shiftService->getShiftHandovers($managerShift, 'to_manager');

            // OPTIMIZED: Pre-calculate delivery apps totals using aggregate query to avoid N+1
            $cashierShiftIds = $handovers->pluck('cashier_shift_id')->toArray();
            $deliveryAppsTotals = ShiftSalesBreakdown::whereIn('cashier_shift_id', $cashierShiftIds)
                ->selectRaw('cashier_shift_id, SUM(amount) as total_amount')
                ->groupBy('cashier_shift_id')
                ->pluck('total_amount', 'cashier_shift_id')
                ->toArray();

            // Prepare cashier breakdown
            $cashierBreakdown = [];
            foreach ($handovers as $handover) {
                $cashierShift = $handover->cashierShift;
                $deliveryApps = (float) ($deliveryAppsTotals[$cashierShift->id] ?? 0);

                $cashierBreakdown[] = [
                    'cashier_name' => $cashierShift->cashier->name,
                    'cashier_id' => $cashierShift->cashier_id,
                    'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                    'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                    'delivery_app_payments' => $deliveryApps,
                    'variance' => (float) ($handover->variance_amount ?? 0),
                    'sales' => (float) ($cashierShift->total_sales ?? 0),
                ];
            }

            // Use financial summary values if manager shift values are 0 or null
            $totalSales = (float) ($managerShift->total_sales > 0 ? $managerShift->total_sales : ($financialSummary['total_sales'] ?? 0));
            $cashCollected = (float) ($managerShift->cash_collected > 0 ? $managerShift->cash_collected : ($financialSummary['cash_collected'] ?? 0));
            $cardPayments = (float) ($managerShift->card_payments > 0 ? $managerShift->card_payments : ($financialSummary['card_payments'] ?? 0));
            $aggregatorPayments = (float) ($managerShift->aggregator_payments > 0 ? $managerShift->aggregator_payments : ($financialSummary['delivery_app_payments'] ?? 0));

            // Calculate VAT and Net Sales from total_sales if not set in manager shift
            $vatAmount = (float) ($managerShift->vat_amount > 0 ? $managerShift->vat_amount : ($totalSales * 0.15));
            $netSales = (float) ($managerShift->net_sales > 0 ? $managerShift->net_sales : ($totalSales - $vatAmount));

            // Calculate expected_balance and variance
            $expectedBalance = $totalSales;
            $closingBalance = (float) ($managerShift->handover_amount ?? $managerShift->closing_balance ?? 0);
            $variance = $expectedBalance - $closingBalance;

            // Determine handover status: pending, accepted, rejected
            $handoverStatus = $this->normalizeHandoverStatus($managerShift->handover_status);

            // Get current time based on handover_timing
            $currentTime = $managerShift->handover_timing === 'yesterday'
                ? now()->subDay()->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s');

            return $this->successResponse([
                'shift' => [
                    'id' => $managerShift->id,
                    'shift_date' => $managerShift->shift_date->format('Y-m-d'),
                    'status' => $managerShift->status,
                    'manager_name' => $managerShift->branchManager->name,
                    'manager_id' => $managerShift->branch_manager_id,
                    'branch_name' => $managerShift->branch->name,
                    'branch_id' => $managerShift->branch_id,
                ],
                'handover' => [
                    'handover_amount' => (float) ($managerShift->handover_amount ?? 0),
                    'status' => $handoverStatus,
                    'status_options' => ['pending', 'accepted', 'rejected'],
                    'handover_from' => $managerShift->branchManager->name,
                    'handover_to' => $managerShift->nextManager?->name ?? 'Not specified',
                    'handover_to_id' => $managerShift->next_manager_id,
                    'handover_date' => $managerShift->handover_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
                    'handover_time' => $managerShift->handover_time?->format('H:i:s') ?? now()->format('H:i:s'),
                    'current_time' => $currentTime,
                    'current_time_setting' => $managerShift->handover_timing ?? 'today',
                    'handover_notes' => $managerShift->handover_notes,
                    'opening_balance' => (float) ($managerShift->opening_balance ?? 0),
                    'closing_balance' => $closingBalance,
                    'expected_balance' => $expectedBalance,
                    'variance' => $variance,
                    'variance_type' => $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None'),
                ],
                'financial_summary' => [
                    'total_sales' => $totalSales,
                    'net_sales' => $netSales,
                    'vat_amount' => $vatAmount,
                    'cash_collected' => $cashCollected,
                    'card_payments' => $cardPayments,
                    'aggregator_payments' => $aggregatorPayments,
                    'total_variance' => (float) ($financialSummary['total_variance'] ?? 0),
                ],
                'cashier_breakdown' => $cashierBreakdown,
                'daily_close_status' => [
                    'is_submitted' => (bool) $managerShift->daily_report_submitted,
                    'submitted_at' => $managerShift->daily_report_submitted_at?->format('Y-m-d H:i:s'),
                    'notes' => $managerShift->daily_report_notes,
                    'can_submit' => !$managerShift->daily_report_submitted,
                    'can_reopen' => $managerShift->can_reopen && $managerShift->daily_report_submitted,
                    'reopened_at' => $managerShift->reopened_at?->format('Y-m-d H:i:s'),
                    'reopen_reason' => $managerShift->reopen_reason,
                ],
            ], 'Manager final handover details retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Shift not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // Helper Methods

    /**
     * Apply filters to shift history query
     * OPTIMIZED: Extract filter logic to reduce code duplication
     */
    private function applyShiftFilters($query, Request $request): void
    {
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('shift_date', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('shift_date', '<=', $to);
        }
    }

    /**
     * Normalize handover status to standard values: pending, accepted, rejected
     */
    private function normalizeHandoverStatus(?string $status): string
    {
        if (in_array($status, ['approved', 'accepted', 'completed'])) {
            return 'accepted';
        }

        if (in_array($status, ['rejected', 'rejected_final'])) {
            return 'rejected';
        }

        return 'pending';
    }

    /**
     * Get correction details (request corrections information)
     * Returns details about who requested corrections, when, and the comment
     *
     * @param \Modules\Shift\Models\ShiftHandoverStatus|null $handoverStatus
     * @return array|null
     */
    private function getCorrectionDetails($handoverStatus): ?array
    {
        if (!$handoverStatus) {
            return null;
        }

        // Only return correction details if:
        // 1. manager_comment exists (correction was requested)
        // 2. reviewed_at exists (correction was processed)
        // 3. Status is rejected (not approved or permanently rejected)
        if (!$handoverStatus->manager_comment || !$handoverStatus->reviewed_at) {
            return null;
        }

        // If permanently rejected, it's not a correction request
        if ($handoverStatus->isPermanentlyRejected()) {
            return null;
        }

        // If status is rejected and manager_comment exists, it's a correction request
        if ($handoverStatus->manager_approval_status === 'rejected') {
            return [
                'requested_by' => $handoverStatus->reviewedBy?->name ?? 'N/A',
                'requested_by_id' => $handoverStatus->reviewed_by_id,
                'requested_by_type' => $this->getReviewerTypeLabel($handoverStatus->reviewed_by_type),
                'manager_comment' => $handoverStatus->manager_comment,
                'requested_at' => $handoverStatus->reviewed_at?->format('Y-m-d H:i:s'),
                'can_cashier_edit' => $handoverStatus->canCashierEdit(),
            ];
        }

        return null;
    }

    /**
     * Get reviewer type in readable format
     *
     * @param string|null $reviewerType
     * @return string|null
     */
    private function getReviewerTypeLabel(?string $reviewerType): ?string
    {
        if (!$reviewerType) {
            return null;
        }

        // Check if it's a BranchManager type
        if (str_contains($reviewerType, 'BranchManager') || $reviewerType === 'branch_manager') {
            return 'Branch Manager';
        }

        // Check if it's a Cashier type
        if (str_contains($reviewerType, 'Cashier') || $reviewerType === 'cashier') {
            return 'Cashier';
        }

        return class_basename($reviewerType);
    }

    /**
     * Calculate financial summary from all cashier shifts in the same day and branch
     * حساب الملخص المالي من جميع شيفتات الكاشيرز في نفس اليوم والبرانش
     * OPTIMIZED: Using Service method with caching + additional controller-level cache
     */
    private function calculateFinancialSummary(BranchManagerShift $shift): array
    {
        // Service already has caching, but add controller-level cache for extra performance
        $cacheKey = "controller_financial_summary_{$shift->id}_{$shift->updated_at->timestamp}";

        return Cache::remember($cacheKey, 300, function () use ($shift) {
            return $this->shiftService->calculateFinancialSummary($shift);
        });
    }

    /**
     * Prepare daily close summary from all cashier shifts for this branch and date.
     * Includes every cashier who worked that day, regardless of whether they submitted
     * a handover to the manager.  Handover data (variance_amount, status) is merged in
     * where available.
     */
    private function prepareDailyCloseSummary(BranchManagerShift $shift): array
    {
        // 1. All cashier shifts for the branch on this date (regardless of handover status)
        $cashierShifts = CashierShift::whereDate('shift_date', $shift->shift_date)
            ->whereHas('shift', fn ($q) => $q->where('branch_id', $shift->branch_id))
            ->whereIn('status', ['in_progress', 'completed'])
            ->with([
                'cashier:id,name',
                'salesBreakdown',
                'handover',
            ])
            ->select([
                'id', 'cashier_id', 'shift_id', 'shift_date',
                'total_sales', 'cash_collected', 'card_payments',
                'variance', 'status',
            ])
            ->get();

        // 2. Pre-fetch handovers directed to this manager to get variance_amount per shift
        $handoversByShiftId = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
            ->where('handover_to_id', $shift->branch_manager_id)
            ->whereIn('cashier_shift_id', $cashierShifts->pluck('id'))
            ->get()
            ->keyBy('cashier_shift_id');

        $cashierBreakdown = $cashierShifts->map(function ($cashierShift) use ($handoversByShiftId) {
            $deliveryApps = $cashierShift->salesBreakdown->sum('amount');
            $handover = $handoversByShiftId->get($cashierShift->id);
            $variance = $handover
                ? (float) ($handover->variance_amount ?? $cashierShift->variance ?? 0)
                : (float) ($cashierShift->variance ?? 0);

            return [
                'cashier_name'         => $cashierShift->cashier->name,
                'cashier_id'           => $cashierShift->cashier_id,
                'cash_collected'       => (float) ($cashierShift->cash_collected ?? 0),
                'card_payments'        => (float) ($cashierShift->card_payments ?? 0),
                'delivery_app_payments'=> (float) $deliveryApps,
                'variance'             => $variance,
                'sales'                => (float) ($cashierShift->total_sales ?? 0),
                'handover_status'      => $handover?->status ?? 'not_submitted',
            ];
        })->values()->all();

        $totals = [
            'total_cash_collected' => (float) collect($cashierBreakdown)->sum('cash_collected'),
            'total_card_payments'  => (float) collect($cashierBreakdown)->sum('card_payments'),
            'total_delivery_apps'  => (float) collect($cashierBreakdown)->sum('delivery_app_payments'),
            'total_variance'       => (float) collect($cashierBreakdown)->sum('variance'),
            'total_sales'          => (float) collect($cashierBreakdown)->sum('sales'),
        ];

        $calculatedClosingBalance  = $totals['total_cash_collected'];
        $calculatedExpectedBalance = $totals['total_sales'];
        $calculatedVariance        = $calculatedExpectedBalance - $calculatedClosingBalance;

        if (!$shift->relationLoaded('branchManager')) {
            $shift->load('branchManager:id,name');
        }
        if (!$shift->relationLoaded('branch')) {
            $shift->load('branch:id,name');
        }

        return [
            'cashier_breakdown' => $cashierBreakdown,
            'totals' => [
                'total_cash_collected' => $totals['total_cash_collected'],
                'total_card_payments'  => $totals['total_card_payments'],
                'total_delivery_apps'  => $totals['total_delivery_apps'],
                'total_variance'       => $totals['total_variance'],
                'total_sales'          => $totals['total_sales'],
            ],
            'manager_summary' => [
                'opening_balance'  => (float) ($shift->opening_balance ?? 0),
                'closing_balance'  => (float) $calculatedClosingBalance,
                'expected_balance' => (float) $calculatedExpectedBalance,
                'variance'         => (float) $calculatedVariance,
                'variance_type'    => $calculatedVariance > 0 ? 'Over' : ($calculatedVariance < 0 ? 'Short' : 'None'),
            ],
            'shift_info' => [
                'date'    => $shift->shift_date->format('Y-m-d'),
                'manager' => $shift->branchManager->name,
                'branch'  => $shift->branch->name,
            ],
        ];
    }
}
