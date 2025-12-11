<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Transformers\ShiftDetailResource;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\HandoverStatus;

class ShiftHandoverController extends Controller
{
    public function __construct(
        private HandoverService $handoverService
    ) {}

    /**
     * Accept handover (for cashier)
     */
    public function acceptHandover(Request $request, string $shift): JsonResponse
    {
        try {
            $cashier = auth()->user();
            $shiftModel = CashierShift::findOrFail($shift);

            if ($shiftModel->next_cashier_id !== $cashier->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This handover is not for you'
                ], 403);
            }

            $this->handoverService->approveHandover(
                $shiftModel,
                $cashier->id,
                get_class($cashier)
            );

            return response()->json([
                'success' => true,
                'message' => 'Handover accepted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record handover
     */
    public function recordHandover(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'next_cashier_id' => 'required|exists:cashiers,id',
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $shiftModel = CashierShift::with(['cashier', 'nextCashier'])->findOrFail($shift);

            if ($shiftModel->status->value !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Shift must be completed before handover',
                ], 400);
            }

            if ($shiftModel->handoverStatus && $shiftModel->handoverStatus->manager_approval_status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover already processed',
                ], 400);
            }

            $handoverStatus = $this->handoverService->recordHandover($shiftModel, $request->all());

            $variance = $shiftModel->total_sales - $request->handover_amount;
            $varianceType = $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None');

            $nextCashier = Cashier::findOrFail($request->next_cashier_id);

            return response()->json([
                'success' => true,
                'message' => 'Handover recorded successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'summary' => [
                        'total_sales' => (float) $shiftModel->total_sales,
                        'sales_breakdown' => [
                            'cash_collected' => (float) $shiftModel->cash_collected,
                            'card_payments' => (float) $shiftModel->card_payments,
                            'delivery_apps' => (float) $shiftModel->salesBreakdown->sum('amount'),
                        ],
                        'handover_details' => [
                            'handover_amount' => (float) $request->handover_amount,
                            'variance' => (float) $variance,
                            'variance_type' => $varianceType,
                            'handover_to' => $nextCashier->name,
                            'next_cashier' => $nextCashier->name,
                            'handover_notes' => $request->handover_notes,
                            'status' => 'pending',
                        ]
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve handover - Section C
     */
    public function approveHandover(Request $request, $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with(['handoverStatus'])->findOrFail($shift);

            if (!$shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift'
                ], 404);
            }

            if (!in_array($shiftModel->handoverStatus->manager_approval_status, ['pending', 'rejected'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover is not pending approval',
                    'current_status' => $shiftModel->handoverStatus->manager_approval_status
                ], 400);
            }

            $manager = auth()->user();

            $result = $this->handoverService->approveHandover(
                $shiftModel,
                $manager->id,
                get_class($manager),
                $request->get('manager_comment')
            );

            return response()->json([
                'success' => true,
                'message' => 'Handover approved successfully',
                'data' => [
                    'shift' => new ShiftDetailResource($result),
                    'approval_details' => [
                        'approved_by' => $manager->name,
                        'approved_at' => now()->format('Y-m-d H:i:s'),
                        'manager_comment' => $request->get('manager_comment'),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Handover approval failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject handover - Section C (with 2-rejection rule)
     */
    public function rejectHandover(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500',
            'manager_comment' => 'nullable|string|max:500',
            'rejection_files' => 'sometimes|array',
            'rejection_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $shiftModel = CashierShift::with(['handoverStatus', 'cashier'])
                ->findOrFail($shift);

            if (!$shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            // Check if can be rejected
            if (!$shiftModel->handoverStatus->canBeRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover cannot be rejected. Either already permanently rejected or not in correct state.',
                    'current_status' => $shiftModel->handoverStatus->manager_approval_status,
                    'rejection_count' => $shiftModel->handoverStatus->rejection_count,
                ], 400);
            }

            $manager = auth()->user();

            $result = $this->handoverService->rejectHandover(
                $shiftModel,
                $manager->id,
                get_class($manager),
                $request->rejection_reason,
                $request->file('rejection_files', []),
                $request->manager_comment
            );

            return response()->json([
                'success' => true,
                'message' => $result['is_final_rejection']
                    ? 'Handover permanently rejected (2nd rejection)'
                    : 'Handover rejected. Cashier can edit and resubmit.',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'rejection_details' => [
                        'status' => $result['handover_status'],
                        'rejected_by' => $manager->name,
                        'rejection_reason' => $request->rejection_reason,
                        'manager_comment' => $request->manager_comment,
                        'rejected_at' => $result['rejected_at'],
                        'rejection_count' => $result['rejection_count'],
                        'is_final_rejection' => $result['is_final_rejection'],
                        'cashier_can_edit' => $result['can_cashier_edit'],
                    ],
                    'next_actions' => [
                        'cashier_can_resubmit' => !$result['is_final_rejection'],
                        'manager_can_reject_again' => !$result['is_final_rejection'],
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Handover rejection failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Edit handover after rejection - Section C (Cashier endpoint)
     */
    public function editHandoverAfterRejection(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'handover_amount' => 'required|numeric|min:0',
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $shiftModel = CashierShift::with(['handoverStatus', 'cashier'])->findOrFail($shift);

            // Verify cashier owns this shift
            $cashier = auth()->user();
            if ($shiftModel->cashier_id !== $cashier->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to edit this handover',
                ], 403);
            }

            // Check if can edit
            if (!$shiftModel->handoverStatus->canCashierEdit()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover cannot be edited. Either not rejected or permanently rejected.',
                    'current_status' => $shiftModel->handoverStatus->manager_approval_status,
                    'rejection_count' => $shiftModel->handoverStatus->rejection_count,
                ], 400);
            }

            $updatedShift = $this->handoverService->recordHandoverEdit($shiftModel, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Handover updated successfully. Resubmitted for manager approval.',
                'data' => [
                    'shift' => new ShiftDetailResource($updatedShift),
                    'edit_details' => [
                        'previous_rejection_count' => $updatedShift->handoverStatus->rejection_count,
                        'new_handover_amount' => (float) $request->handover_amount,
                        'new_variance' => (float) $updatedShift->variance,
                        'edited_at' => $updatedShift->handoverStatus->edited_at->format('Y-m-d H:i:s'),
                        'status' => 'pending',
                    ],
                    'warning' => $updatedShift->handoverStatus->rejection_count === 1
                        ? 'This is your last chance. One more rejection will be permanent.'
                        : null,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to edit handover',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get handover status and details
     */
    public function getHandoverStatus(string $shift): JsonResponse
    {
        try {
            $shiftModel = CashierShift::with([
                'handoverStatus.reviewedBy',
                'handover.handoverTo',
                'handover.approvedBy',
                'cashier',
                'nextCashier'
            ])->findOrFail($shift);

            if (!$shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            $handoverStatus = $shiftModel->handoverStatus;
            $handover = $shiftModel->handover;

            // Get handover_to information
            $handoverToId = $handover?->handover_to_id ?? $shiftModel->nextCashier?->id;
            $handoverToName = null;
            $handoverToType = $handover?->handover_to_type ?? null;

            if ($handover && $handover->handoverTo) {
                $handoverToName = $handover->handoverTo->name;
            } elseif ($handover && $handover->handover_to_type === 'cashier') {
                $handoverToName = $shiftModel->nextCashier?->name ?? 'N/A';
            } elseif ($handover && $handover->handover_to_type === 'branch_manager') {
                $handoverToName = \Modules\BranchManagers\Models\BranchManager::find($handover->handover_to_id)?->name ?? 'N/A';
            } else {
                $handoverToName = $shiftModel->nextCashier?->name ?? 'N/A';
            }

            return response()->json([
                'success' => true,
                'message' => 'Handover status retrieved successfully',
                'data' => [
                    'status' => $handoverStatus->status->value,
                    'manager_approval_status' => $handoverStatus->manager_approval_status,
                    'handover_amount' => (float) ($handover?->handover_amount ?? $shiftModel->handover_amount ?? $shiftModel->closing_balance ?? 0),
                    'variance' => (float) $shiftModel->variance,
                    'variance_type' => $shiftModel->variance > 0 ? 'Over' : ($shiftModel->variance < 0 ? 'Short' : 'None'),
                    'handover_from' => $shiftModel->cashier?->name ?? null,
                    'handover_from_id' => $shiftModel->cashier_id ?? null,
                    'handover_to' => [
                        'id' => $handoverToId,
                        'name' => $handoverToName,
                        'type' => $handoverToType,
                    ],
                    'handover_date' => $handover?->handover_date?->format('Y-m-d') ?? $shiftModel->handed_over_at?->format('Y-m-d'),
                    'handover_time' => $handover?->handover_time?->format('H:i:s') ?? $shiftModel->handed_over_at?->format('H:i:s'),
                    'handover_notes' => $shiftModel->handover_notes ?? $handover?->handover_notes,
                    'handed_over_at' => $shiftModel->handed_over_at?->format('Y-m-d H:i:s'),
                    'reviewed_by' => $handoverStatus->reviewedBy?->name,
                    'reviewed_at' => $handoverStatus->reviewed_at?->format('Y-m-d H:i:s'),
                    'actioned_by' => $handover?->approvedBy ? [
                        'id' => $handover->approved_by_id,
                        'name' => $handover->approvedBy->name,
                        'type' => $handover->approved_by_type,
                        'actioned_at' => $handover->approved_at?->format('Y-m-d H:i:s'),
                    ] : null,
                    'rejection_details' => [
                        'rejection_reason' => $handoverStatus->rejection_reason,
                        'rejection_count' => $handoverStatus->rejection_count,
                        'first_rejected_at' => $handoverStatus->first_rejected_at?->format('Y-m-d H:i:s'),
                        'second_rejected_at' => $handoverStatus->second_rejected_at?->format('Y-m-d H:i:s'),
                        'is_permanently_rejected' => $handoverStatus->isPermanentlyRejected(),
                        'can_cashier_edit' => $handoverStatus->canCashierEdit(),
                        'rejection_files' => $handoverStatus->rejection_files
                            ? collect($handoverStatus->rejection_files)->map(function ($file) {
                                return asset('storage/' . $file);
                            })
                            : [],
                    ],
                    'manager_comment' => $handoverStatus->manager_comment,
                    'was_edited_after_rejection' => $handoverStatus->was_edited_after_rejection,
                    'edited_at' => $handoverStatus->edited_at?->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handover status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available cashiers for handover
     */
    public function getAvailableCashiers(string $shift): JsonResponse
    {
        try {
            // Optimized eager loading
            $shiftModel = CashierShift::with([
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'branch_id']);
                }
            ])->findOrFail($shift);

            // Get all active cashiers for this branch (optimized)
            $allCashiers = Cashier::where('branch_id', $shiftModel->shift->branch_id)
                ->where('status', 'active')
                ->select(['id', 'name', 'email', 'image', 'status'])
                ->get();

            // Transform cashiers to match required format
            $availableCashiers = $allCashiers->map(function ($cashier) {
                return [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email,
                    'image' => $cashier->image ? asset('storage/' . $cashier->image) : null,
                    'is_available' => true,
                    'reason_disabled' => null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Available cashiers retrieved successfully',
                'data' => [
                    'cashiers' => $availableCashiers,
                    'total_count' => $availableCashiers->count(),
                    'available_count' => $availableCashiers->where('is_available', true)->count(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get handover summary
     */
    public function getHandoverSummaries(): JsonResponse
    {
        try {
            $user = auth()->user();
            $branchId = $user->branch_id;

            if (!$branchId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch manager is not assigned to any branch',
                ], 400);
            }

            $summaries = $this->handoverService->getHandoverSummaries([
                'branch_id' => $branchId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Handover summaries retrieved successfully',
                'data' => $summaries
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve handover summaries', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handover summaries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Rejection Details
     * View rejection details: cashier name, reason, uploaded files
     */
    public function getRejectionDetails(Request $request, string $shift): JsonResponse
    {
        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::with([
                'cashier',
                'handoverStatus',
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'branch_id']);
                }
            ])->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this rejection',
                ], 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (!$handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            if (!$handoverStatus->isManagerRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This handover is not rejected',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Rejection details retrieved successfully',
                'data' => [
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
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve rejection details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process Rejection Decision
     * Approve rejection (make it final) or request corrections (add comment, allow cashier to edit)
     */
    public function processRejectionDecision(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->input(), [
            'decision' => 'required|in:approve_rejection,request_corrections',
            'manager_comment' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::with([
                'cashier',
                'handoverStatus',
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'branch_id']);
                }
            ])->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to process this rejection',
                ], 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (!$handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            if (!$handoverStatus->isManagerRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This handover is not rejected',
                ], 400);
            }

            if ($handoverStatus->isPermanentlyRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This rejection is already final and cannot be modified',
                ], 400);
            }

            $decision = $request->input('decision');
            $comment = $request->input('manager_comment');

            if ($decision === 'approve_rejection') {
                // Approve rejection = make it final (2nd rejection)
                if ($handoverStatus->rejection_count >= 2) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Rejection is already final',
                    ], 400);
                }

                // Make it final rejection
                $handoverStatus->update([
                    'manager_approval_status' => 'rejected_final',
                    'rejection_count' => 2,
                    'second_rejected_at' => now(),
                    'manager_comment' => $comment,
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

                return response()->json([
                    'success' => true,
                    'message' => 'Rejection approved and finalized. Cashier cannot edit anymore.',
                    'data' => [
                        'decision' => 'approve_rejection',
                        'rejection_details' => [
                            'cashier_name' => $shiftModel->cashier->name,
                            'rejection_count' => 2,
                            'is_final_rejection' => true,
                            'manager_comment' => $comment,
                            'processed_at' => now()->format('Y-m-d H:i:s'),
                        ],
                    ],
                ]);
            } else {
                // Request corrections = add comment, keep as rejected (cashier can edit)
                $handoverStatus->update([
                    'manager_comment' => $comment,
                    'reviewed_by_id' => $manager->id,
                    'reviewed_by_type' => get_class($manager),
                    'reviewed_at' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Corrections requested. Cashier can edit and resubmit.',
                    'data' => [
                        'decision' => 'request_corrections',
                        'rejection_details' => [
                            'cashier_name' => $shiftModel->cashier->name,
                            'rejection_count' => $handoverStatus->rejection_count,
                            'is_final_rejection' => false,
                            'manager_comment' => $comment,
                            'can_cashier_edit' => true,
                            'processed_at' => now()->format('Y-m-d H:i:s'),
                        ],
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process rejection decision', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process rejection decision',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
