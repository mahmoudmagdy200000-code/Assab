<?php

namespace Modules\Shift\Http\Controllers;

use App\Support\ShiftMoneyValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Transformers\ShiftDetailResource;

class ShiftHandoverController extends Controller
{
    public function __construct(
        private HandoverService $handoverService
    ) {}

    /**
     * Accept handover (for cashier - receiving next cashier only)
     */
    public function acceptHandover(Request $request, string $shift): JsonResponse
    {
        try {
            $cashier = auth()->user();
            $validator = Validator::make($request->all(), [
                'confirmed_amount' => 'required|'.ShiftMoneyValidation::SAR,
                'receiving_shift_id' => 'nullable|uuid|exists:cashier_shifts,id',
                'comment' => 'nullable|string|max:500',
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $shiftModel = CashierShift::withoutEagerLoads()->findOrFail($shift);

            // Sender must not change status.
            if ((string) $shiftModel->cashier_id === (string) $cashier->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: Only the recipient can accept this handover',
                ], 403);
            }

            // Determine the actual recipient from the handover record
            $handover = CashierShiftHandover::query()
                ->where('cashier_shift_id', $shiftModel->id)
                ->where('status', 'pending')
                ->first();
            if (! $handover) {
                return response()->json([
                    'success' => false,
                    'message' => 'No pending handover exists for this shift',
                ], 409);
            }
            if ($handover->handover_to_type !== 'cashier') {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: This handover is designated for a branch manager',
                ], 403);
            }
            if ((string) $handover->handover_to_id !== (string) $cashier->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This handover is not for you',
                ], 403);
            }

            $this->handoverService->acceptHandoverByCashier(
                $shiftModel,
                $cashier->id,
                (string) $request->input('confirmed_amount'),
                $request->input('receiving_shift_id'),
                $request->get('comment')
            );

            return response()->json([
                'success' => true,
                'message' => 'Handover accepted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Record handover
     */
    public function recordHandover(Request $request, string $shift): JsonResponse
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($request);
        $validator = Validator::make($request->all(), [
            'next_cashier_id' => 'required|exists:cashiers,id',
            'handover_amount' => 'required|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth()->user();

            // Check if user is cashier or manager
            $isCashier = $user instanceof \Modules\Cashier\Models\Cashier;
            $isManager = $user instanceof \Modules\BranchManagers\Models\BranchManager;

            if (! $isCashier && ! $isManager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $shiftModel = CashierShift::with(['cashier', 'shift', 'nextCashier'])->findOrFail($shift);

            // If cashier, verify it's their shift
            if ($isCashier && $shiftModel->cashier_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to you',
                ], 403);
            }

            // If manager, verify shift belongs to their branch
            if ($isManager && $shiftModel->shift->branch_id !== $user->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to your branch',
                ], 403);
            }

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

            // Verify next cashier belongs to same branch
            if ($isManager && $nextCashier->branch_id !== $user->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: The selected cashier does not belong to your branch',
                ], 403);
            }

            if ($isCashier && $nextCashier->branch_id !== $shiftModel->cashier->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: The selected cashier does not belong to your branch',
                ], 403);
            }

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
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve handover - Section C
     */
    public function approveHandover(Request $request, $shift): JsonResponse
    {
        try {
            $manager = auth()->user();

            // Ensure the user is a branch manager
            if (! $manager || ! $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $shiftModel = CashierShift::with(['handoverStatus', 'shift'])->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to your branch',
                ], 403);
            }

            if (! $shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            // if (!in_array($shiftModel->handoverStatus->manager_approval_status, ['pending', 'rejected'])) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Handover is not pending approval',
            //         'current_status' => $shiftModel->handoverStatus->manager_approval_status
            //     ], 400);
            // }

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
                        'manager_comment' => $request->get('manager_comment') ?? null,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Handover approval failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject handover
     * - Cashier path (next cashier): rejects the incoming handover via rejectHandoverByCashier()
     * - Manager path: rejects with 2-rejection rule via rejectHandover()
     */
    public function rejectHandover(Request $request, string $shift): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'nullable|string|max:500',
            'manager_comment' => 'nullable|string|max:500',
            'rejection_files' => 'nullable|array',
            'rejection_files.*' => 'nullable|file|mimes:pdf,png,jpeg,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth()->user();
            $isCashier = $user instanceof \Modules\Cashier\Models\Cashier;

            // ── Cashier path: receiving cashier rejects the incoming handover ──
            if ($isCashier) {
                $shiftModel = CashierShift::with(['handoverStatus', 'handover'])->findOrFail($shift);

                // Sender must not change status.
                if ((string) $shiftModel->cashier_id === (string) $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Forbidden: Only the recipient can reject this handover',
                    ], 403);
                }

                // Determine the actual recipient from the handover record
                $handover = $shiftModel->handover;
                if ($handover && $handover->handover_to_type === 'branch_manager') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Forbidden: This handover is designated for a branch manager',
                    ], 403);
                }

                $recipientId = $handover?->handover_to_id ?? $shiftModel->next_cashier_id;
                if ((string) $recipientId !== (string) $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: This handover is not for you',
                    ], 403);
                }

                if (! $shiftModel->handoverStatus) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No handover found for this shift',
                    ], 404);
                }

                $this->handoverService->rejectHandoverByCashier(
                    $shiftModel,
                    $user->id,
                    $request->rejection_reason ?? '',
                    $request->file('rejection_files', [])
                );

                $fresh = $shiftModel->fresh(['handoverStatus']);

                return response()->json([
                    'success' => true,
                    'message' => 'Handover rejected. Shift reverted to in progress; sender must end shift again.',
                    'data' => [
                        'shift' => new ShiftDetailResource($fresh),
                        'rejection_details' => [
                            'status' => $fresh->handoverStatus?->manager_approval_status ?? 'rejected',
                            'rejected_by' => $user->name,
                            'rejection_reason' => $request->rejection_reason ?? null,
                            'manager_comment' => null,
                            'rejected_at' => now()->format('Y-m-d H:i:s'),
                            'rejection_count' => $fresh->handoverStatus?->rejection_count ?? 1,
                            'is_final_rejection' => false,
                            'cashier_can_edit' => true,
                        ],
                        'next_actions' => [
                            'cashier_can_resubmit' => true,
                            'manager_can_reject_again' => true,
                        ],
                    ],
                ]);
            }

            // ── Manager path: branch manager rejects with 2-rejection rule ──
            $manager = $user;

            if (! $manager || ! $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $shiftModel = CashierShift::with(['handoverStatus', 'cashier', 'shift'])
                ->findOrFail($shift);

            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to your branch',
                ], 403);
            }

            if (! $shiftModel->handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            if (! $shiftModel->handoverStatus->canBeRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Handover cannot be rejected. Either already permanently rejected or not in correct state.',
                    'current_status' => $shiftModel->handoverStatus->manager_approval_status,
                    'rejection_count' => $shiftModel->handoverStatus->rejection_count,
                ], 400);
            }

            $result = $this->handoverService->rejectHandover(
                $shiftModel,
                $manager->id,
                get_class($manager),
                $request->rejection_reason ?? null,
                $request->file('rejection_files', []),
                $request->manager_comment
            );

            return response()->json([
                'success' => true,
                'message' => $result['is_final_rejection']
                    ? 'Handover permanently rejected (2nd rejection)'
                    : 'Handover rejected. Shift reverted to in progress; cashier must end shift again.',
                'data' => [
                    'shift' => new ShiftDetailResource($shiftModel->fresh()),
                    'rejection_details' => [
                        'status' => $result['handover_status'],
                        'rejected_by' => $manager->name,
                        'rejection_reason' => $request->rejection_reason ?? null,
                        'manager_comment' => $request->manager_comment,
                        'rejected_at' => $result['rejected_at'],
                        'rejection_count' => $result['rejection_count'],
                        'is_final_rejection' => $result['is_final_rejection'],
                        'cashier_can_edit' => $result['can_cashier_edit'],
                    ],
                    'next_actions' => [
                        'cashier_can_resubmit' => ! $result['is_final_rejection'],
                        'manager_can_reject_again' => ! $result['is_final_rejection'],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Handover rejection failed', [
                'shift_id' => $shift,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit handover after rejection - Section C (Cashier endpoint)
     */
    public function editHandoverAfterRejection(Request $request, string $shift): JsonResponse
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($request);
        $validator = Validator::make($request->all(), [
            'handover_amount' => 'required|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
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
            if (! $shiftModel->handoverStatus->canCashierEdit()) {
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
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to edit handover',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get handover status and details
     */
    public function getHandoverStatus(string $shift): JsonResponse
    {
        try {
            $user = auth()->user();
            $shiftModel = CashierShift::with([
                'handoverStatus.reviewedBy',
                'handover.handoverTo',
                'handover.approvedBy',
                'cashier',
                'nextCashier',
            ])->findOrFail($shift);

            // Cashier must be the shift owner or the designated receiving cashier (from shift or handover record)
            if ($user instanceof \Modules\Cashier\Models\Cashier) {
                $isOwner = (string) $shiftModel->cashier_id === (string) $user->id;
                $isReceiver = (string) $shiftModel->next_cashier_id === (string) $user->id
                    || ($shiftModel->handover && $shiftModel->handover->handover_to_type === 'cashier' && (string) $shiftModel->handover->handover_to_id === (string) $user->id);
                if (! $isOwner && ! $isReceiver) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: You do not have access to this shift handover',
                    ], 403);
                }
            }

            if (! $shiftModel->handoverStatus) {
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
                        'type' => $this->normalizeActionedByType($handover->approved_by_type),
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
                                return asset('storage/'.$file);
                            })
                            : [],
                    ],
                    'manager_comment' => $handoverStatus->manager_comment,
                    'was_edited_after_rejection' => $handoverStatus->was_edited_after_rejection,
                    'edited_at' => $handoverStatus->edited_at?->format('Y-m-d H:i:s'),
                    'correction_details' => $this->getCorrectionDetails($handoverStatus),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handover status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get responsibility details for a shift (variance approval context).
     * Returns: cashier name, branch name, variance amount, and the current responsibility_status.
     *
     * responsibility_status:
     *   - not_submitted : cashier has not recorded variance details yet
     *   - pending       : details submitted, waiting for manager review
     *   - approved      : manager approved the responsibility
     *   - rejected      : manager rejected the responsibility
     *
     * Branch Manager: any shift in their branch. Cashier: own shift only.
     */
    public function getResponsibilityDetails(string $shift): JsonResponse
    {
        try {
            $user = auth()->user();
            $shiftModel = CashierShift::with([
                'cashier:id,name',
                'shift:id,name,branch_id',
                'shift.branch:id,name',
                'varianceDetails',
            ])->findOrFail($shift);

            if ($user instanceof Cashier) {
                $isShiftOwner = $shiftModel->cashier_id === $user->id;
                $isResponsibleParty = $shiftModel->varianceDetails->contains('responsible_cashier_id', $user->id);
                if (! $isShiftOwner && ! $isResponsibleParty) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: This shift does not belong to you',
                    ], 403);
                }
            } elseif ($user instanceof \Modules\BranchManagers\Models\BranchManager) {
                if ($shiftModel->shift->branch_id !== $user->branch_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: This shift is not in your branch',
                    ], 403);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $variance = (float) ($shiftModel->variance ?? 0);
            $varianceType = $variance > 0 ? 'Over' : ($variance < 0 ? 'Short' : 'None');

            $isCashierUser = $user instanceof Cashier;

            if ($isCashierUser && $shiftModel->cashier_id !== $user->id) {
                // This cashier is not the shift owner — they may be a responsible_cashier_id
                $myDetail = $shiftModel->varianceDetails->firstWhere('responsible_cashier_id', $user->id);

                if (! $myDetail) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No responsibility record assigned to you on this shift',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Responsibility details retrieved successfully',
                    'data' => [
                        'shift_id' => $shiftModel->id,
                        'cashier_name' => $user->name,
                        'branch_name' => $shiftModel->shift?->branch?->name ?? null,
                        'variance_amount' => $variance,
                        'variance_type' => $varianceType,
                        'shift_date' => $shiftModel->shift_date?->format('Y-m-d'),
                        'assigned_amount' => (float) $myDetail->assigned_amount,
                        'responsibility_status' => $myDetail->responsibility_status,
                        'rejection_reason' => $myDetail->rejection_reason,
                        'reviewed_at' => $myDetail->reviewed_at?->format('Y-m-d H:i:s'),
                    ],
                ]);
            }

            // Shift owner (cashier) or branch manager — show overall shift responsibility status
            $varianceDetail = $shiftModel->varianceDetails->first();
            if (! $varianceDetail) {
                $responsibilityStatus = 'not_submitted';
                $rejectionReason = null;
                $reviewedAt = null;
            } else {
                $responsibilityStatus = $varianceDetail->responsibility_status;
                $rejectionReason = $varianceDetail->rejection_reason;
                $reviewedAt = $varianceDetail->reviewed_at?->format('Y-m-d H:i:s');
            }

            // For manager: also include per-cashier breakdown when multiple cashiers are responsible
            $responsibleCashiers = null;
            if (! $isCashierUser && $shiftModel->varianceDetails->isNotEmpty()) {
                $responsibleCashiers = $shiftModel->varianceDetails
                    ->whereNotNull('responsible_cashier_id')
                    ->map(fn ($d) => [
                        'cashier_id' => $d->responsible_cashier_id,
                        'cashier_name' => $d->responsibleCashier?->name ?? null,
                        'assigned_amount' => (float) $d->assigned_amount,
                        'responsibility_status' => $d->responsibility_status,
                        'rejection_reason' => $d->rejection_reason,
                        'reviewed_at' => $d->reviewed_at?->format('Y-m-d H:i:s'),
                    ])
                    ->values();
            }

            return response()->json([
                'success' => true,
                'message' => 'Responsibility details retrieved successfully',
                'data' => array_filter([
                    'shift_id' => $shiftModel->id,
                    'cashier_name' => $shiftModel->cashier?->name ?? null,
                    'branch_name' => $shiftModel->shift?->branch?->name ?? null,
                    'variance_amount' => $variance,
                    'variance_type' => $varianceType,
                    'shift_date' => $shiftModel->shift_date?->format('Y-m-d'),
                    'responsibility_status' => $responsibilityStatus,
                    'rejection_reason' => $rejectionReason,
                    'reviewed_at' => $reviewedAt,
                    'responsible_cashiers' => $responsibleCashiers,
                ], fn ($v) => $v !== null),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve responsibility details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get correction details (request corrections information)
     * Returns details about who requested corrections, when, and the comment
     *
     * @param  ShiftHandoverStatus  $handoverStatus
     */
    private function getCorrectionDetails($handoverStatus): ?array
    {
        // Only return correction details if:
        // 1. manager_comment exists (correction was requested)
        // 2. reviewed_at exists (correction was processed)
        // 3. Status is rejected (not approved or permanently rejected)
        if (! $handoverStatus->manager_comment || ! $handoverStatus->reviewed_at) {
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
     */
    private function getReviewerTypeLabel(?string $reviewerType): ?string
    {
        if (! $reviewerType) {
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
     * Normalize approved_by_type to branch_manager or cashier for API response.
     */
    private function normalizeActionedByType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        if (str_contains($type, 'BranchManager') || $type === 'branch_manager') {
            return 'branch_manager';
        }
        if (str_contains($type, 'Cashier') || $type === 'cashier') {
            return 'cashier';
        }

        return $type;
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
                },
            ])->findOrFail($shift);

            // Cashier caller must own this shift
            $user = auth()->user();
            if ($user instanceof \Modules\Cashier\Models\Cashier
                && (string) $shiftModel->cashier_id !== (string) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This shift does not belong to you',
                ], 403);
            }

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
                    'image' => $cashier->image ? asset('storage/'.$cashier->image) : null,
                    'type' => 'cashier',
                    'is_available' => true,
                    'disabled' => false,
                    'reason_disabled' => null,
                ];
            })->values();

            // Include branch managers as handover recipients (same branch)
            $branchManagers = \Modules\BranchManagers\Models\BranchManager::where('branch_id', $shiftModel->shift->branch_id)
                ->where('is_active', true)
                ->where('status', 'active')
                ->get(['id', 'name', 'email', 'image']);

            $branchManagerEntries = $branchManagers->map(function ($manager) {
                return [
                    'id' => $manager->id,
                    'name' => $manager->name.' (Branch Manager)',
                    'email' => $manager->email ?? null,
                    'image' => $manager->image ? asset('storage/'.$manager->image) : null,
                    'type' => 'branch_manager',
                    'is_available' => true,
                    'disabled' => false,
                    'reason_disabled' => null,
                ];
            });

            $allRecipients = $availableCashiers->concat($branchManagerEntries->values());

            return response()->json([
                'success' => true,
                'message' => 'Available cashiers retrieved successfully',
                'data' => [
                    'cashiers' => $allRecipients->values()->all(),
                    'total_count' => $allRecipients->count(),
                    'available_count' => $allRecipients->where('is_available', true)->count(),
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

            if (! $branchId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch manager is not assigned to any branch',
                ], 400);
            }

            $summaries = $this->handoverService->getHandoverSummaries([
                'branch_id' => $branchId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Handover summaries retrieved successfully',
                'data' => $summaries,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve handover summaries', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handover summaries',
                'error' => $e->getMessage(),
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
                },
            ])->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this rejection',
                ], 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (! $handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            if (! $handoverStatus->isManagerRejected()) {
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
                    'can_approve_rejection' => ! $handoverStatus->isPermanentlyRejected(),
                    'can_request_corrections' => $handoverStatus->rejection_count === 1,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve rejection details',
                'error' => $e->getMessage(),
            ], 500);
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
        $requestData = ! empty($jsonData) ? $jsonData : $request->all();

        $validator = Validator::make($requestData, [
            'decision' => 'nullable|in:approve_rejection,request_corrections',
            'manager_comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'debug' => [
                    'request_data' => $requestData,
                    'json_data' => $request->json()->all(),
                    'all_data' => $request->all(),
                    'content_type' => $request->header('Content-Type'),
                ],
            ], 422);
        }

        try {
            $manager = auth()->user();

            $shiftModel = CashierShift::with([
                'cashier',
                'handoverStatus',
                'shift' => function ($q) {
                    $q->select(['id', 'name', 'branch_id']);
                },
            ])->findOrFail($shift);

            // Verify shift belongs to manager's branch
            if ($shiftModel->shift->branch_id !== $manager->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to process this rejection',
                ], 403);
            }

            $handoverStatus = $shiftModel->handoverStatus;

            if (! $handoverStatus) {
                return response()->json([
                    'success' => false,
                    'message' => 'No handover found for this shift',
                ], 404);
            }

            if (! $handoverStatus->isManagerRejected()) {
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

            $decision = $requestData['decision'] ?? $request->decision;
            $comment = $requestData['manager_comment'] ?? $request->manager_comment;

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
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
