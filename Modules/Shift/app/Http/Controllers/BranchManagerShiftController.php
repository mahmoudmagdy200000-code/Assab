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

            // Detailed handovers TO BRANCH MANAGER (نفس فورمات getHandoffsReceived)
            $handoffsToManager = $managerShift->cashierHandovers->map(function ($handover) {
                $cashierShift = $handover->cashierShift;
                $shift = $cashierShift->shift;

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
                                'cashier_name' => $detail->responsibleCashier?->name,
                                'amount' => (float) $detail->amount,
                                'notes' => $detail->notes,
                            ];
                        })->toArray();
                    }
                }

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
                    'variance_details' => $varianceDetails,
                    'handover_to_type' => $handover->handover_to_type,
                ];
            });

            // Handovers بين الكاشيرز في نفس الفرع واليوم
            $cashierToCashierHandovers = \Modules\Shift\Models\CashierShiftHandover::query()
                ->where('handover_to_type', 'cashier')
                ->whereDate('handover_date', $managerShift->shift_date)
                ->whereHas('cashierShift.shift', function ($q) use ($managerShift) {
                    $q->where('branch_id', $managerShift->branch_id);
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.shift',
                    'cashierShift.salesBreakdown.aggregator',
                    'cashierShift.varianceDetails.responsibleCashier',
                    'approvedBy'
                ])
                ->get()
                ->map(function ($handover) {
                    $cashierShift = $handover->cashierShift;
                    $shift = $cashierShift->shift;

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
                                    'cashier_name' => $detail->responsibleCashier?->name,
                                    'amount' => (float) $detail->amount,
                                    'notes' => $detail->notes,
                                ];
                            })->toArray();
                        }
                    }

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
                        'variance_details' => $varianceDetails,
                        'handover_to_type' => $handover->handover_to_type,
                    ];
                });

            // Section A: Shift Overview Response
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
                    'between_cashiers' => $cashierToCashierHandovers,
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
     */
    public function getHandoffsReceived(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get today's shift with handovers to branch manager
            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->with(['cashierHandovers' => function ($query) use ($manager) {
                    $query->where('handover_to_type', 'branch_manager')
                        ->where('handover_to_id', $manager->id)
                        ->with([
                            'cashierShift.cashier',
                            'cashierShift.shift',
                            'cashierShift.salesBreakdown.aggregator',
                            'cashierShift.varianceDetails.responsibleCashier',
                            'approvedBy'
                        ]);
                }])
                ->firstOrFail();

            // Transform handovers TO BRANCH MANAGER
            $handoffsToManager = $managerShift->cashierHandovers->map(function ($handover) {
                $cashierShift = $handover->cashierShift;
                $shift = $cashierShift->shift;

                // Get variance details
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

                    // Add other cashiers if variance involves multiple cashiers
                    if ($cashierShift->varianceDetails) {
                        $varianceDetails['other_cashiers'] = $cashierShift->varianceDetails->map(function ($detail) {
                            return [
                                'cashier_id' => $detail->responsible_cashier_id,
                                'cashier_name' => $detail->responsibleCashier?->name,
                                'amount' => (float) $detail->amount,
                                'notes' => $detail->notes,
                            ];
                        })->toArray();
                    }
                }

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
                    'variance_details' => $varianceDetails,
                    'handover_to_type' => $handover->handover_to_type,
                ];
            });

            // 🔹 Handovers بين الكاشيرز في نفس الفرع واليوم
            $cashierToCashierHandovers = \Modules\Shift\Models\CashierShiftHandover::query()
                ->where('handover_to_type', 'cashier')
                ->whereDate('handover_date', $managerShift->shift_date)
                ->whereHas('cashierShift.shift', function ($q) use ($managerShift) {
                    $q->where('branch_id', $managerShift->branch_id);
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.shift',
                    'cashierShift.salesBreakdown.aggregator',
                    'cashierShift.varianceDetails.responsibleCashier',
                    'approvedBy'
                ])
                ->get()
                ->map(function ($handover) {
                    $cashierShift = $handover->cashierShift;
                    $shift = $cashierShift->shift;

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
                                    'cashier_name' => $detail->responsibleCashier?->name,
                                    'amount' => (float) $detail->amount,
                                    'notes' => $detail->notes,
                                ];
                            })->toArray();
                        }
                    }

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
                        'variance_details' => $varianceDetails,
                        'handover_to_type' => $handover->handover_to_type,
                    ];
                });

            $summary = $managerShift->getHandoverSummary();

            return $this->successResponse([
                'handoffs' => [
                    'to_branch_manager' => $handoffsToManager,
                    'between_cashiers' => $cashierToCashierHandovers,
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
        // Default shift duration: 8 hours (can be configured)
        $defaultShiftHours = 8;
        $defaultStartTime = '09:00';
        $defaultEndTime = '17:00';

        $progress = [
            'title' => "Branch Manager Shift - " . $shift->shift_date->format('d M Y'),
            'description' => "Managing daily operations and cashier handovers",
            'status' => $shift->status === 'not_started' ? 'Not Started' : ($shift->status === 'in_progress' ? 'In Progress' : 'Completed'),
            'start_time' => $shift->actual_start_time?->format('H:i') ?? $defaultStartTime,
            'end_time' => $shift->actual_end_time?->format('H:i') ?? $defaultEndTime,
            'elapsed_hours' => 0,
            'progress_percentage' => 0,
        ];

        if ($shift->status === 'in_progress' && $shift->actual_start_time) {
            // Calculate based on actual start time and expected end time
            $expectedEndTime = $shift->actual_end_time ?? $shift->actual_start_time->copy()->addHours($defaultShiftHours);
            $totalMinutes = $shift->actual_start_time->diffInMinutes($expectedEndTime);
            $elapsedMinutes = now()->diffInMinutes($shift->actual_start_time);

            $progress['elapsed_hours'] = round($elapsedMinutes / 60, 2);
            $progress['progress_percentage'] = $totalMinutes > 0
                ? min(($elapsedMinutes / $totalMinutes) * 100, 100)
                : 0;
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
     */
    public function endShift(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'nullable|exists:branch_managers,id',
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

            // Update shift with handover details AND financial totals
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
                'total_sales' => $financialSummary['total_sales'] ?? 0,
                'cash_collected' => $financialSummary['cash_collected'] ?? 0,
                'card_payments' => $financialSummary['card_payments'] ?? 0,
                'aggregator_payments' => $financialSummary['delivery_app_payments'] ?? 0,
            ]);

            // Determine handover status based on requirements
            // Status: Completed (only when handover is received), Not Submitted, Pending
            $handoverStatus = 'Not Submitted';
            if ($managerShift->handover_status === 'completed' || $managerShift->handover_status === 'approved') {
                $handoverStatus = 'Completed';
            } elseif ($managerShift->handover_status === 'pending') {
                $handoverStatus = 'Pending';
            }

            // Get current time based on handover_timing
            $currentTime = $request->handover_timing === 'yesterday'
                ? now()->subDay()->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s');

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                // Section D: Final Handover and End Shift - Exact format as per requirements
                'final_handover' => [
                    'handover_amount' => (float) $request->handover_amount,
                    'status' => $handoverStatus, // Completed, Not Submitted, or Pending
                    'status_options' => ['Completed', 'Not Submitted', 'Pending'],
                    'handover_from' => $manager->name,
                    'handover_to' => $managerShift->nextManager?->name ?? 'Not specified',
                    'handover_date' => $managerShift->handover_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
                    'handover_time' => $managerShift->handover_time?->format('H:i:s') ?? now()->format('H:i:s'),
                    'current_time' => $currentTime,
                    'current_time_setting' => $request->handover_timing, // 'today' or 'yesterday'
                    'handover_notes' => $managerShift->handover_notes,
                ],
                // Section E: Daily Totals (calculated across all cashiers)
                'daily_totals' => [
                    'total_cash_collected' => (float) ($financialSummary['cash_collected'] ?? 0),
                    'total_card_payments' => (float) ($financialSummary['card_payments'] ?? 0),
                    'total_delivery_apps' => (float) ($financialSummary['delivery_app_payments'] ?? 0),
                    'total_variance' => (float) ($financialSummary['total_variance'] ?? 0),
                    'total_sales' => (float) ($financialSummary['total_sales'] ?? 0),
                    'shift_date' => $managerShift->shift_date->format('Y-m-d'),
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


    /**
     * Calculate financial summary from all cashier shifts in the same day and branch
     * حساب الملخص المالي من جميع شيفتات الكاشيرز في نفس اليوم والبرانش
     */
    private function calculateFinancialSummary(BranchManagerShift $shift): array
    {
        // الحصول على جميع شيفتات الكاشيرز المكتملة في نفس اليوم والبرانش
        $allCashierShifts = $shift->getAllCashierShifts()
            ->where('status', \Modules\Shift\Enums\ShiftStatus::COMPLETED);

        $summary = [
            'total_sales' => 0,
            'cash_collected' => 0,
            'card_payments' => 0,
            'delivery_app_payments' => 0,
            'total_variance' => 0,
        ];

        foreach ($allCashierShifts as $cashierShift) {
            // Calculate delivery app payments from sales breakdown (all aggregators)
            $deliveryApps = $cashierShift->salesBreakdown
                ->sum('amount');

            $summary['total_sales'] += $cashierShift->total_sales ?? 0;
            $summary['cash_collected'] += $cashierShift->cash_collected ?? 0;
            $summary['card_payments'] += $cashierShift->card_payments ?? 0;
            $summary['delivery_app_payments'] += $deliveryApps;

            // حساب الـ variance من الـ handover إذا كان موجود، أو من الـ shift مباشرة
            $handover = $cashierShift->handoverStatus;
            if ($handover && $handover->status === \Modules\Shift\Enums\HandoverStatus::ACCEPTED) {
                // إذا كان الـ handover للبرانش مانجر، استخدم variance_amount من handover
                $cashierHandover = \Modules\Shift\Models\CashierShiftHandover::where('cashier_shift_id', $cashierShift->id)
                    ->where('handover_to_type', 'branch_manager')
                    ->where('handover_to_id', $shift->branch_manager_id)
                    ->first();

                if ($cashierHandover) {
                    $summary['total_variance'] += $cashierHandover->variance_amount ?? 0;
                } else {
                    $summary['total_variance'] += $cashierShift->variance ?? 0;
                }
            } else {
                $summary['total_variance'] += $cashierShift->variance ?? 0;
            }
        }

        return $summary;
    }

    /**
     * Prepare daily close summary from all cashier shifts
     * إعداد ملخص الإغلاق اليومي من جميع شيفتات الكاشيرز
     */
    private function prepareDailyCloseSummary(BranchManagerShift $shift): array
    {
        // الحصول على جميع شيفتات الكاشيرز المكتملة في نفس اليوم والبرانش
        $allCashierShifts = $shift->getAllCashierShifts()
            ->where('status', \Modules\Shift\Enums\ShiftStatus::COMPLETED);

        $cashierBreakdown = [];
        $totals = [
            'total_cash_collected' => 0,
            'total_card_payments' => 0,
            'total_delivery_apps' => 0,
            'total_variance' => 0,
            'total_sales' => 0,
        ];

        foreach ($allCashierShifts as $cashierShift) {
            // Calculate delivery app payments from sales breakdown (all aggregators)
            $deliveryApps = $cashierShift->salesBreakdown
                ->sum('amount');

            // حساب الـ variance من الـ handover إذا كان موجود
            $variance = $cashierShift->variance ?? 0;
            $cashierHandover = \Modules\Shift\Models\CashierShiftHandover::where('cashier_shift_id', $cashierShift->id)
                ->where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $shift->branch_manager_id)
                ->where('status', 'approved')
                ->first();

            if ($cashierHandover) {
                $variance = $cashierHandover->variance_amount ?? $variance;
            }

            // Section E: Per-cashier breakdown - Exact field names as per requirements
            $breakdown = [
                'cashier_name' => $cashierShift->cashier->name,
                'cashier_id' => $cashierShift->cashier_id,
                'cash_collected' => (float) ($cashierShift->cash_collected ?? 0), // ✅ Cash Collected
                'card_payments' => (float) ($cashierShift->card_payments ?? 0), // ✅ Card Payments
                'delivery_app_payments' => (float) $deliveryApps, // ✅ Delivery App Payments
                'variance' => (float) $variance, // ✅ Variance
                'sales' => (float) ($cashierShift->total_sales ?? 0), // ✅ Sales
            ];

            $cashierBreakdown[] = $breakdown;

            // Update totals according to Section E requirements
            $totals['total_cash_collected'] += $breakdown['cash_collected'];
            $totals['total_card_payments'] += $breakdown['card_payments'];
            $totals['total_delivery_apps'] += $breakdown['delivery_app_payments'];
            $totals['total_variance'] += $breakdown['variance'];
            $totals['total_sales'] += $breakdown['sales'];
        }

        // Section E: Final Daily Close - Exact format as per requirements
        return [
            // Per-cashier breakdown (before submission)
            'cashier_breakdown' => $cashierBreakdown, // Each item contains: cash_collected, card_payments, delivery_app_payments, variance, sales
            // Totals (calculated across all cashiers)
            'totals' => [
                'total_cash_collected' => (float) $totals['total_cash_collected'],
                'total_card_payments' => (float) $totals['total_card_payments'],
                'total_delivery_apps' => (float) $totals['total_delivery_apps'],
                'total_variance' => (float) $totals['total_variance'],
                'total_sales' => (float) $totals['total_sales'],
            ],
            // Additional manager summary (optional)
            'manager_summary' => [
                'opening_balance' => (float) ($shift->opening_balance ?? 0),
                'closing_balance' => (float) ($shift->closing_balance ?? 0),
                'expected_balance' => (float) ($shift->expected_balance ?? 0),
                'variance' => (float) ($shift->variance ?? 0),
                'variance_type' => ($shift->variance ?? 0) > 0 ? 'Over' : (($shift->variance ?? 0) < 0 ? 'Short' : 'None'),
            ],
            'shift_info' => [
                'date' => $shift->shift_date->format('Y-m-d'),
                'manager' => $shift->branchManager->name,
                'branch' => $shift->branch->name,
            ],
        ];
    }
}
