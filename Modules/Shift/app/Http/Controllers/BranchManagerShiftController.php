<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            // The cashierHandovers relationship already filters by branch_manager_id and handover_to_type
            $managerShift->load([
                'branch',
                'nextManager',
                'cashierHandovers' => function ($query) {
                    $query->with([
                        'cashierShift.cashier',
                        'cashierShift.shift',
                        'cashierShift.salesBreakdown.aggregator',
                        'cashierShift.varianceDetails.responsibleCashier',
                        'approvedBy'
                    ]);
                }
            ]);

            // Calculate progress
            $progress = $this->calculateShiftProgress($managerShift);

            // Get handovers summary
            $handoversSummary = $managerShift->getHandoverSummary();

            // Detailed handovers TO BRANCH MANAGER (نفس فورمات getHandoffsReceived)
            // Use direct query instead of relationship to ensure we get all handovers
            $handoversToManager = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $manager->id)
                ->whereHas('cashierShift', function ($query) use ($managerShift) {
                    $query->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        });
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.shift',
                    'cashierShift.salesBreakdown.aggregator',
                    'cashierShift.varianceDetails.responsibleCashier',
                    'approvedBy'
                ])
                ->get();

            $handoffsToManager = $handoversToManager->map(function ($handover) {
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
     */
    public function getHandoffsReceived(Request $request): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Get today's shift with handovers to branch manager
            // The cashierHandovers relationship already filters by branch_manager_id and handover_to_type
            $managerShift = BranchManagerShift::where('branch_manager_id', $manager->id)
                ->whereDate('shift_date', today())
                ->with(['cashierHandovers' => function ($query) {
                    $query->with([
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
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_timing' => 'required|in:today,yesterday',
            'handover_notes' => 'nullable|string|max:500',
            // Financial values (optional - can be manually entered)
            'total_sales' => 'nullable|numeric|min:0',
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
            'cashier_breakdown.*.sales' => 'nullable|numeric|min:0',
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

            // Get all handovers for this manager shift
            $handovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $manager->id)
                ->whereHas('cashierShift', function ($query) use ($managerShift) {
                    $query->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        });
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.salesBreakdown.aggregator'
                ])
                ->get();

            // If cashier_breakdown is provided, update each cashier shift
            if ($request->has('cashier_breakdown') && is_array($request->cashier_breakdown)) {
                foreach ($request->cashier_breakdown as $breakdown) {
                    $cashierId = $breakdown['cashier_id'] ?? null;
                    if (!$cashierId) {
                        continue;
                    }

                    // Find the cashier shift for this cashier
                    $cashierShift = \Modules\Shift\Models\CashierShift::where('cashier_id', $cashierId)
                        ->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        })
                        ->first();

                    if ($cashierShift) {
                        // Update cashier shift with provided values
                        $updateCashierData = [];

                        if (isset($breakdown['sales'])) {
                            $updateCashierData['total_sales'] = $breakdown['sales'];
                            // Calculate VAT and Net Sales
                            $updateCashierData['vat_amount'] = $breakdown['sales'] * 0.15;
                            $updateCashierData['net_sales'] = $breakdown['sales'] - $updateCashierData['vat_amount'];
                        }

                        if (isset($breakdown['cash_collected'])) {
                            $updateCashierData['cash_collected'] = $breakdown['cash_collected'];
                        }

                        if (isset($breakdown['card_payments'])) {
                            $updateCashierData['card_payments'] = $breakdown['card_payments'];
                        }

                        // Update variance if provided
                        if (isset($breakdown['variance'])) {
                            $updateCashierData['variance'] = $breakdown['variance'];
                        }

                        // Update delivery app payments if provided
                        if (isset($breakdown['delivery_app_payments'])) {
                            // Delete existing sales breakdown and create new one with total amount
                            // Note: If there are multiple aggregators, we'll sum them into one entry
                            // Or we can distribute the amount proportionally
                            $existingBreakdown = $cashierShift->salesBreakdown;

                            if ($existingBreakdown->isNotEmpty()) {
                                // If there's only one aggregator, update it
                                if ($existingBreakdown->count() === 1) {
                                    $existingBreakdown->first()->update(['amount' => $breakdown['delivery_app_payments']]);
                                } else {
                                    // If multiple aggregators, delete all and create one with total
                                    $firstAggregator = $existingBreakdown->first();
                                    $aggregatorId = $firstAggregator->aggregator_id;

                                    $existingBreakdown->each->delete();

                                    \Modules\Shift\Models\ShiftSalesBreakdown::create([
                                        'cashier_shift_id' => $cashierShift->id,
                                        'aggregator_id' => $aggregatorId,
                                        'amount' => $breakdown['delivery_app_payments'],
                                    ]);
                                }
                            }
                        }

                        if (!empty($updateCashierData)) {
                            $cashierShift->update($updateCashierData);
                        }

                        // Update handover variance if handover exists
                        $handover = $handovers->firstWhere('cashierShift.cashier_id', $cashierId);
                        if ($handover && isset($breakdown['variance'])) {
                            $handover->update(['variance_amount' => $breakdown['variance']]);
                        }
                    }
                }

                // Refresh handovers after updates
                $handovers->load('cashierShift.cashier', 'cashierShift.salesBreakdown.aggregator');
            }

            // Calculate financial summary from updated cashier shifts
            $financialSummary = $this->calculateFinancialSummary($managerShift);

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
                // Calculate from sum of approved cashier handovers to this manager
                $approvedHandovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                    ->where('handover_to_id', $manager->id)
                    ->where('status', 'approved')
                    ->whereHas('cashierShift', function ($query) use ($managerShift) {
                        $query->whereDate('shift_date', $managerShift->shift_date)
                            ->whereHas('shift', function ($q) use ($managerShift) {
                                $q->where('branch_id', $managerShift->branch_id);
                            });
                    })
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

            // Refresh the model to get updated relationships
            $managerShift->refresh();
            $managerShift->load('nextManager');

            // Prepare cashier breakdown for response (from updated data)
            $cashierBreakdownResponse = [];
            $updatedHandovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $manager->id)
                ->whereHas('cashierShift', function ($query) use ($managerShift) {
                    $query->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        });
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.salesBreakdown.aggregator'
                ])
                ->get();

            foreach ($updatedHandovers as $handover) {
                $cashierShift = $handover->cashierShift;
                $deliveryApps = $cashierShift->salesBreakdown->sum('amount');

                $cashierBreakdownResponse[] = [
                    'cashier_name' => $cashierShift->cashier->name,
                    'cashier_id' => $cashierShift->cashier_id,
                    'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                    'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                    'delivery_app_payments' => (float) $deliveryApps,
                    'variance' => (float) ($handover->variance_amount ?? 0),
                    'sales' => (float) ($cashierShift->total_sales ?? 0),
                ];
            }

            return $this->successResponse([
                'shift' => new BranchManagerShiftResource($managerShift),
                // Section D: Final Handover and End Shift - Exact format as per requirements
                'final_handover' => [
                    'handover_amount' => (float) ($managerShift->handover_amount ?? $closingBalance),
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
     * Section E: Update Final Daily Close
     * تعديل Final Daily Close بعد إنهاء الـ shift
     */
    public function updateFinalDailyClose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_to' => 'nullable|exists:branch_managers,id',
            'handover_amount' => 'nullable|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
            // Financial values (optional - can be manually entered)
            'total_sales' => 'nullable|numeric|min:0',
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
            'cashier_breakdown.*.sales' => 'nullable|numeric|min:0',
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

            // Get all handovers for this manager shift
            $handovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $manager->id)
                ->whereHas('cashierShift', function ($query) use ($managerShift) {
                    $query->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        });
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.salesBreakdown.aggregator'
                ])
                ->get();

            // If cashier_breakdown is provided, update each cashier shift
            if ($request->has('cashier_breakdown') && is_array($request->cashier_breakdown)) {
                foreach ($request->cashier_breakdown as $breakdown) {
                    $cashierId = $breakdown['cashier_id'] ?? null;
                    if (!$cashierId) {
                        continue;
                    }

                    // Find the cashier shift for this cashier
                    $cashierShift = CashierShift::where('cashier_id', $cashierId)
                        ->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        })
                        ->first();

                    if ($cashierShift) {
                        // Update cashier shift with provided values
                        $updateCashierData = [];

                        if (isset($breakdown['sales'])) {
                            $updateCashierData['total_sales'] = $breakdown['sales'];
                            // Calculate VAT and Net Sales
                            $updateCashierData['vat_amount'] = $breakdown['sales'] * 0.15;
                            $updateCashierData['net_sales'] = $breakdown['sales'] - $updateCashierData['vat_amount'];
                        }

                        if (isset($breakdown['cash_collected'])) {
                            $updateCashierData['cash_collected'] = $breakdown['cash_collected'];
                        }

                        if (isset($breakdown['card_payments'])) {
                            $updateCashierData['card_payments'] = $breakdown['card_payments'];
                        }

                        // Update variance if provided
                        if (isset($breakdown['variance'])) {
                            $updateCashierData['variance'] = $breakdown['variance'];
                        }

                        // Update delivery app payments if provided
                        if (isset($breakdown['delivery_app_payments'])) {
                            $existingBreakdown = $cashierShift->salesBreakdown;

                            if ($existingBreakdown->isNotEmpty()) {
                                // If there's only one aggregator, update it
                                if ($existingBreakdown->count() === 1) {
                                    $existingBreakdown->first()->update(['amount' => $breakdown['delivery_app_payments']]);
                                } else {
                                    // If multiple aggregators, delete all and create one with total
                                    $firstAggregator = $existingBreakdown->first();
                                    $aggregatorId = $firstAggregator->aggregator_id;

                                    $existingBreakdown->each->delete();

                                    ShiftSalesBreakdown::create([
                                        'cashier_shift_id' => $cashierShift->id,
                                        'aggregator_id' => $aggregatorId,
                                        'amount' => $breakdown['delivery_app_payments'],
                                    ]);
                                }
                            }
                        }

                        if (!empty($updateCashierData)) {
                            $cashierShift->update($updateCashierData);
                        }

                        // Update handover variance if handover exists
                        $handover = $handovers->firstWhere('cashierShift.cashier_id', $cashierId);
                        if ($handover && isset($breakdown['variance'])) {
                            $handover->update(['variance_amount' => $breakdown['variance']]);
                        }
                    }
                }

                // Refresh handovers after updates
                $handovers->load('cashierShift.cashier', 'cashierShift.salesBreakdown.aggregator');
            }

            // Calculate financial summary from updated cashier shifts
            $financialSummary = $this->calculateFinancialSummary($managerShift);

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
                // Calculate from sum of approved cashier handovers to this manager
                $approvedHandovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                    ->where('handover_to_id', $manager->id)
                    ->where('status', 'approved')
                    ->whereHas('cashierShift', function ($query) use ($managerShift) {
                        $query->whereDate('shift_date', $managerShift->shift_date)
                            ->whereHas('shift', function ($q) use ($managerShift) {
                                $q->where('branch_id', $managerShift->branch_id);
                            });
                    })
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

            // Refresh the model to get updated relationships
            $managerShift->refresh();
            $managerShift->load('nextManager');

            // Prepare cashier breakdown for response (from updated data)
            $cashierBreakdownResponse = [];
            $updatedHandovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $manager->id)
                ->whereHas('cashierShift', function ($query) use ($managerShift) {
                    $query->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        });
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.salesBreakdown.aggregator'
                ])
                ->get();

            foreach ($updatedHandovers as $handover) {
                $cashierShift = $handover->cashierShift;
                $deliveryApps = $cashierShift->salesBreakdown->sum('amount');

                $cashierBreakdownResponse[] = [
                    'cashier_name' => $cashierShift->cashier->name,
                    'cashier_id' => $cashierShift->cashier_id,
                    'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                    'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                    'delivery_app_payments' => (float) $deliveryApps,
                    'variance' => (float) ($handover->variance_amount ?? 0),
                    'sales' => (float) ($cashierShift->total_sales ?? 0),
                ];
            }

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

    /**
     * Get Cashier to Cashier Handover Details by ID
     * جلب تفاصيل handover بين الكاشيرز بالـ ID
     */
    public function getCashierHandoverDetails(string $handoverId): JsonResponse
    {
        try {
            $manager = auth()->user();

            $handover = \Modules\Shift\Models\CashierShiftHandover::where('id', $handoverId)
                ->where('handover_to_type', 'cashier')
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.shift',
                    'cashierShift.salesBreakdown.aggregator',
                    'cashierShift.varianceDetails.responsibleCashier',
                    'handoverTo',
                    'approvedBy'
                ])
                ->firstOrFail();

            $cashierShift = $handover->cashierShift;
            $shift = $cashierShift->shift;

            // Check if handover belongs to manager's branch
            if ($shift->branch_id !== $manager->branch_id) {
                return $this->errorResponse('You do not have access to this handover', 403);
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
                            'cashier_name' => $detail->responsibleCashier?->name,
                            'amount' => (float) $detail->amount,
                            'notes' => $detail->notes,
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
     */
    public function getManagerFinalHandover(string $shiftId): JsonResponse
    {
        try {
            $manager = auth()->user();

            $managerShift = BranchManagerShift::where('id', $shiftId)
                ->where('branch_manager_id', $manager->id)
                ->with([
                    'branchManager',
                    'nextManager',
                    'branch'
                ])
                ->firstOrFail();

            // Calculate financial summary
            $financialSummary = $this->calculateFinancialSummary($managerShift);

            // Get all handovers for this manager shift
            $handovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $manager->id)
                ->whereHas('cashierShift', function ($query) use ($managerShift) {
                    $query->whereDate('shift_date', $managerShift->shift_date)
                        ->whereHas('shift', function ($q) use ($managerShift) {
                            $q->where('branch_id', $managerShift->branch_id);
                        });
                })
                ->with([
                    'cashierShift.cashier',
                    'cashierShift.salesBreakdown.aggregator'
                ])
                ->get();

            // Prepare cashier breakdown
            $cashierBreakdown = [];
            foreach ($handovers as $handover) {
                $cashierShift = $handover->cashierShift;
                $deliveryApps = $cashierShift->salesBreakdown->sum('amount');

                $cashierBreakdown[] = [
                    'cashier_name' => $cashierShift->cashier->name,
                    'cashier_id' => $cashierShift->cashier_id,
                    'cash_collected' => (float) ($cashierShift->cash_collected ?? 0),
                    'card_payments' => (float) ($cashierShift->card_payments ?? 0),
                    'delivery_app_payments' => (float) $deliveryApps,
                    'variance' => (float) ($handover->variance_amount ?? 0),
                    'sales' => (float) ($cashierShift->total_sales ?? 0),
                ];
            }

            // Calculate expected_balance and variance
            $expectedBalance = (float) ($managerShift->total_sales ?? $financialSummary['total_sales'] ?? 0);
            $closingBalance = (float) ($managerShift->handover_amount ?? $managerShift->closing_balance ?? 0);
            $variance = $expectedBalance - $closingBalance;

            // Determine handover status
            $handoverStatus = 'Not Submitted';
            if ($managerShift->handover_status === 'completed' || $managerShift->handover_status === 'approved') {
                $handoverStatus = 'Completed';
            } elseif ($managerShift->handover_status === 'pending') {
                $handoverStatus = 'Pending';
            }

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
                    'status_options' => ['Completed', 'Not Submitted', 'Pending'],
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
                    'total_sales' => (float) ($managerShift->total_sales ?? $financialSummary['total_sales'] ?? 0),
                    'net_sales' => (float) ($managerShift->net_sales ?? 0),
                    'vat_amount' => (float) ($managerShift->vat_amount ?? 0),
                    'cash_collected' => (float) ($managerShift->cash_collected ?? $financialSummary['cash_collected'] ?? 0),
                    'card_payments' => (float) ($managerShift->card_payments ?? $financialSummary['card_payments'] ?? 0),
                    'aggregator_payments' => (float) ($managerShift->aggregator_payments ?? $financialSummary['delivery_app_payments'] ?? 0),
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
     * Calculate financial summary from all cashier shifts in the same day and branch
     * حساب الملخص المالي من جميع شيفتات الكاشيرز في نفس اليوم والبرانش
     */
    private function calculateFinancialSummary(BranchManagerShift $shift): array
    {
        // الحصول على جميع handovers للبرانش مانجر في نفس اليوم والبرانش
        $handovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
            ->where('handover_to_id', $shift->branch_manager_id)
            ->whereHas('cashierShift', function ($query) use ($shift) {
                $query->whereDate('shift_date', $shift->shift_date)
                    ->whereHas('shift', function ($q) use ($shift) {
                        $q->where('branch_id', $shift->branch_id);
                    });
            })
            ->with([
                'cashierShift.cashier',
                'cashierShift.salesBreakdown.aggregator'
            ])
            ->get();

        $summary = [
            'total_sales' => 0,
            'cash_collected' => 0,
            'card_payments' => 0,
            'delivery_app_payments' => 0,
            'total_variance' => 0,
        ];

        foreach ($handovers as $handover) {
            $cashierShift = $handover->cashierShift;

            // Use data from cashier shift
            $summary['total_sales'] += $cashierShift->total_sales ?? 0;
            $summary['cash_collected'] += $cashierShift->cash_collected ?? 0;
            $summary['card_payments'] += $cashierShift->card_payments ?? 0;

            // Calculate delivery app payments from sales breakdown (all aggregators)
            $deliveryApps = $cashierShift->salesBreakdown
                ->sum('amount');
            $summary['delivery_app_payments'] += $deliveryApps;

            // Use variance from handover
            $summary['total_variance'] += $handover->variance_amount ?? 0;
        }

        return $summary;
    }

    /**
     * Prepare daily close summary from all cashier shifts
     * إعداد ملخص الإغلاق اليومي من جميع شيفتات الكاشيرز
     */
    private function prepareDailyCloseSummary(BranchManagerShift $shift): array
    {
        // الحصول على جميع handovers للبرانش مانجر في نفس اليوم والبرانش
        $handovers = \Modules\Shift\Models\CashierShiftHandover::where('handover_to_type', 'branch_manager')
            ->where('handover_to_id', $shift->branch_manager_id)
            ->whereHas('cashierShift', function ($query) use ($shift) {
                $query->whereDate('shift_date', $shift->shift_date)
                    ->whereHas('shift', function ($q) use ($shift) {
                        $q->where('branch_id', $shift->branch_id);
                    });
            })
            ->with([
                'cashierShift.cashier',
                'cashierShift.salesBreakdown.aggregator'
            ])
            ->get();

        $cashierBreakdown = [];
        $totals = [
            'total_cash_collected' => 0,
            'total_card_payments' => 0,
            'total_delivery_apps' => 0,
            'total_variance' => 0,
            'total_sales' => 0,
        ];

        foreach ($handovers as $handover) {
            $cashierShift = $handover->cashierShift;

            // Calculate delivery app payments from sales breakdown (all aggregators)
            $deliveryApps = $cashierShift->salesBreakdown
                ->sum('amount');

            // Use variance from handover
            $variance = $handover->variance_amount ?? 0;

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
        // closing_balance = مجموع cash_collected من جميع الكاشيرز
        $calculatedClosingBalance = $totals['total_cash_collected'];
        // expected_balance = total_sales
        $calculatedExpectedBalance = $totals['total_sales'];
        // variance = expected_balance - closing_balance
        $calculatedVariance = $calculatedExpectedBalance - $calculatedClosingBalance;

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
                'closing_balance' => (float) $calculatedClosingBalance, // مجموع cash_collected من جميع الكاشيرز
                'expected_balance' => (float) $calculatedExpectedBalance, // total_sales
                'variance' => (float) $calculatedVariance,
                'variance_type' => $calculatedVariance > 0 ? 'Over' : ($calculatedVariance < 0 ? 'Short' : 'None'),
            ],
            'shift_info' => [
                'date' => $shift->shift_date->format('Y-m-d'),
                'manager' => $shift->branchManager->name,
                'branch' => $shift->branch->name,
            ],
        ];
    }
}
