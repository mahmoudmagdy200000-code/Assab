<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Models\ShiftVarianceAlert;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftHistoryAction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Exceptions\HandoverException;
use Modules\Custody\Models\PersonalLedgerTransaction;

/**
 * HandoverService
 *
 * Manages all handover operations including:
 * - Recording handovers (to cashier or branch manager)
 * - Approval/Rejection workflow with 2-rejection rule
 * - Auto-handover between consecutive shifts
 * - Handover summaries and statistics
 */
class HandoverService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Record a new handover
     * Supports both cashier-to-cashier and cashier-to-manager handovers
     *
     * @param CashierShift $shift
     * @param array $data
     * @return CashierShift
     */
    public function recordHandover(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            $handoverToType = $data['handover_to_type'] ?? 'cashier';
            $handoverToId = $data['handover_to_id'] ?? $data['next_cashier_id'] ?? null;

            Log::info('Recording handover', [
                'shift_id' => $shift->id,
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
            ]);

            // Update shift with handover details so the recipient sees the request in cashier requests
            $nextCashierId = $data['next_cashier_id'] ?? ($handoverToType === 'cashier' ? $handoverToId : null);
            if ($handoverToType === 'cashier' && $nextCashierId) {
                $shift->update([
                    'next_cashier_id' => $nextCashierId,
                ]);
            }

            // Calculate variance using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            // Note: handover_amount is the cash being handed over, not used for variance calculation
            $variance = $shift->calculateVariance();
            $expectedBalance = $shift->total_sales;

            $shift->update([
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? null,
                'handed_over_at' => now(),
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            // Handle variance files upload
            $varianceFiles = null;
            if (!empty($data['variance_files'])) {
                $varianceFiles = $this->uploadVarianceFiles($data['variance_files'], $shift->id);
            }

            // Create CashierShiftHandover record
            $handoverData = [
                'cashier_shift_id' => $shift->id,
                'handover_to_id' => $handoverToId,
                'handover_to_type' => $handoverToType,
                'handover_amount' => $data['handover_amount'],
                'variance_amount' => $variance,
                'variance_reason' => $data['variance_reason'] ?? null,
                'variance_files' => $varianceFiles,
                'handover_notes' => $data['handover_notes'] ?? null,
                'handover_date' => now()->toDateString(), // Use actual handover date, not shift date
                'handover_time' => now(),
                'status' => 'pending',
            ];

            Log::info('Creating CashierShiftHandover', [
                'handover_data' => $handoverData,
            ]);

            $handover = CashierShiftHandover::create($handoverData);

            // Create or update ShiftHandoverStatus for approval tracking
            // Use updateOrCreate to avoid duplicate entry errors
            ShiftHandoverStatus::updateOrCreate(
                ['cashier_shift_id' => $shift->id],
                [
                    'status' => HandoverStatus::PENDING,
                    'manager_approval_status' => 'pending',
                ]
            );

            // Record history
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_RECORDED->value,
                null,
                [
                    'handover_to_type' => $handoverToType,
                    'handover_to_id' => $handoverToId,
                    'next_cashier_id' => $data['next_cashier_id'] ?? null,
                    'handover_amount' => $data['handover_amount'],
                    'variance' => $variance,
                ]
            );

            DB::commit();

            // Record Cash-IN (Total Sales) only if this shift does not already have one for this cashier
            // (e.g. from End Shift Only). Avoids double-counting when handover is sent after end shift only.
            if ($shift->cashier) {
                try {
                    $alreadyHasTotalSales = \Modules\Custody\Models\CashierCustodyTransaction::where('related_shift_id', $shift->id)
                        ->where('cashier_id', $shift->cashier->id)
                        ->where('transaction_type', 'Total Sales')
                        ->exists();

                    if (!$alreadyHasTotalSales) {
                        app(\Modules\Custody\Services\CashierCustodyService::class)
                            ->recordCashCollected($handover, $shift->cashier);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to create Total Sales entry', [
                        'error'       => $e->getMessage(),
                        'handover_id' => $handover->id,
                    ]);
                }
            }

            // Clear cache for branch manager shift so workday/current shows new handover immediately
            if ($handoverToType === 'branch_manager' && $handoverToId) {
                try {
                    $branchManagerShift = BranchManagerShift::where('branch_manager_id', $handoverToId)
                        ->whereDate('shift_date', $handover->handover_date ?? $shift->shift_date)
                        ->first();

                    if ($branchManagerShift) {
                        app(BranchManagerShiftService::class)->clearShiftCaches($branchManagerShift);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to clear cache after handover', [
                        'error' => $e->getMessage(),
                        'handover_id' => $handover->id,
                    ]);
                }
            }

            Log::info('Handover recorded successfully', [
                'shift_id' => $shift->id,
                'handover_id' => $handover->id,
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
            ]);

            return $shift->fresh(['nextCashier', 'handoverStatus']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to record handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Approve a handover (by Branch Manager)
     *
     * Business Rule: Changes status from Pending → Approved
     *
     * @param CashierShift $shift
     * @param string $reviewerId
     * @param string $reviewerType
     * @param string|null $managerComment
     * @return CashierShift
     */
    public function approveHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        ?string $managerComment = null
    ): CashierShift {
        DB::beginTransaction();
        try {
            // Ensure handoverStatus exists
            // Use updateOrCreate to avoid duplicate entry errors
            if (!$shift->handoverStatus) {
                ShiftHandoverStatus::updateOrCreate(
                    ['cashier_shift_id' => $shift->id],
                    [
                        'status' => HandoverStatus::PENDING,
                        'manager_approval_status' => 'pending',
                    ]
                );
                $shift->refresh();
            }

            // Check if can be approved
            // if (!$shift->handoverStatus->canBeApproved()) {
            //     throw new \Exception('Handover cannot be approved in current state. Status: ' . $shift->handoverStatus->manager_approval_status);
            // }

            // Approve using model method
            $shift->handoverStatus->approve($reviewerId, $reviewerType, $managerComment);

            // Mark shift as completed
            $shift->update([
                'status' => ShiftStatus::COMPLETED,
            ]);

            // Update CashierShiftHandover status
            $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->first();
            if ($handover) {
                $handover->update([
                    'status' => 'approved',
                    'approved_by_id' => $reviewerId,
                    'approved_by_type' => $reviewerType,
                    'approved_at' => now(),
                ]);

                // Fire event for personal ledger transaction creation
                if ($handover->handover_to_type === 'branch_manager') {
                    event(new \Modules\Custody\Events\HandoverApproved($handover));

                    // Record Cash-OUT custody entry for the sending cashier (handover to BM)
                    // The entry is deferred to approval because the handover is not final until approved.
                    if ($shift->cashier) {
                        try {
                            app(\Modules\Custody\Services\CashierCustodyService::class)
                                ->recordHandoverSent($handover, $shift->cashier);
                        } catch (\Exception $e) {
                            Log::warning('Failed to create cashier custody cash-out entry on BM approval', [
                                'error' => $e->getMessage(),
                                'handover_id' => $handover->id,
                            ]);
                        }
                    }
                }

                // Re-dispatch VarianceRecorded so the branch manager ledger entry is created/updated
                // (variance may have been recorded before the handover was submitted)
                $shiftFresh = $shift->fresh(['varianceDetails']);
                if ($shiftFresh && $shiftFresh->varianceDetails->isNotEmpty()) {
                    event(new \Modules\Shift\Events\VarianceRecorded($shiftFresh));
                }
            }

            // Try auto handover to next shift
            try {
                $this->autoHandover($shift);
            } catch (\Throwable $ex) {
                Log::warning('Auto handover failed but approval succeeded', [
                    'shift_id' => $shift->id,
                    'error' => $ex->getMessage(),
                ]);
            }

            // Record history
            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_APPROVED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'manager_approval_status' => 'approved',
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
                ],
                $managerComment
            );

            // Reload relationships
            $shift->load([
                'handoverStatus.reviewedBy',
                'nextCashier',
                'cashier',
                'shift',
                'salesBreakdown.aggregator',
                'varianceDetails.responsibleCashier',
            ]);

            DB::commit();

            // Clear branch manager shift cache so workday/current returns updated status immediately
            $this->clearBranchManagerShiftCacheForApproval($shift, $reviewerId);

            return $shift;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Clear branch manager shift cache after approval so workday/current returns fresh data immediately.
     * workday/current always uses the manager's shift for today(), so we clear that same shift's cache.
     */
    private function clearBranchManagerShiftCacheForApproval(CashierShift $shift, string $reviewerId): void
    {
        $service = app(BranchManagerShiftService::class);
        try {
            // Clear for today() - same as workday/current (firstOrCreate shift_date => today())
            $forToday = BranchManagerShift::where('branch_manager_id', $reviewerId)
                ->whereDate('shift_date', Carbon::today())
                ->first();
            if ($forToday) {
                $service->clearShiftCaches($forToday);
            }
            // If cashier shift date differs from today, clear that manager shift too (e.g. approval next day)
            $shiftDate = $shift->shift_date instanceof \Carbon\Carbon
                ? $shift->shift_date->format('Y-m-d')
                : \Carbon\Carbon::parse($shift->shift_date)->format('Y-m-d');
            if ($shiftDate !== Carbon::today()->format('Y-m-d')) {
                $forShiftDate = BranchManagerShift::where('branch_manager_id', $reviewerId)
                    ->whereDate('shift_date', $shiftDate)
                    ->first();
                if ($forShiftDate) {
                    $service->clearShiftCaches($forShiftDate);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to clear branch manager shift cache after approval', [
                'shift_id' => $shift->id,
                'reviewer_id' => $reviewerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear BM workday caches for every manager in the cashier shift's branch (cashier-driven handover actions).
     */
    private function clearBranchManagerShiftCachesForBranch(CashierShift $shift): void
    {
        $shift->loadMissing('shift');
        $branchId = $shift->shift?->branch_id;
        if (!$branchId) {
            return;
        }

        $managerIds = \Modules\BranchManagers\Models\BranchManager::where('branch_id', $branchId)
            ->pluck('id');
        foreach ($managerIds as $managerId) {
            $this->clearBranchManagerShiftCacheForApproval($shift, (string) $managerId);
        }
    }

    /**
     * After a handover rejection that allows the cashier to redo end-shift: reset shift data, strip custody, remove handover rows.
     */
    public function revertCashierShiftAfterHandoverRejection(CashierShift $shift, array $audit = []): void
    {
        $shift = $shift->fresh();

        if ($audit !== []) {
            $shift->recordHistory(
                'handover_rejected_shift_reverted',
                ['status' => $shift->status->value],
                array_merge([
                    'status' => ShiftStatus::IN_PROGRESS->value,
                ], $audit)
            );
        }

        try {
            app(\Modules\Custody\Services\CashierCustodyService::class)
                ->deleteTransactionsForCashierShift($shift->id);
        } catch (\Exception $e) {
            Log::warning('Failed to delete custody transactions for reverted shift', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);
        }

        PersonalLedgerTransaction::where('related_shift_id', $shift->id)
            ->where('transaction_type', 'Variance from Cashier')
            ->delete();

        ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->delete();
        ShiftVarianceAlert::where('cashier_shift_id', $shift->id)->delete();
        ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->delete();

        CashierShiftHandover::where('cashier_shift_id', $shift->id)->delete();
        ShiftHandoverStatus::where('cashier_shift_id', $shift->id)->delete();

        $shift->update([
            'status' => ShiftStatus::IN_PROGRESS,
            'total_sales' => 0,
            'net_sales' => 0,
            'vat_amount' => 0,
            'cash_collected' => 0,
            'card_payments' => 0,
            'pos_receipt' => null,
            'closing_balance' => 0,
            'expected_balance' => 0,
            'variance' => 0,
            'handed_over_at' => null,
            'handover_notes' => null,
            'next_cashier_id' => null,
            'actual_end_time' => null,
        ]);
    }

    /**
     * Reject a handover (by Branch Manager)
     *
     * Business Rules:
     * - First rejection: Cashier can edit and resubmit
     * - Second rejection: Status permanently changes to 'rejected_final'
     *
     * @param CashierShift $shift
     * @param string $reviewerId
     * @param string $reviewerType
     * @param string $reason
     * @param array $files
     * @param string|null $comment
     * @return array
     */
    public function rejectHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        string $reason,
        array $files = [],
        ?string $comment = null
    ): array {
        DB::beginTransaction();
        try {
            $shift->loadMissing('handoverStatus');
            $handoverStatus = $shift->handoverStatus;

            if (!$handoverStatus) {
                ShiftHandoverStatus::updateOrCreate(
                    ['cashier_shift_id' => $shift->id],
                    [
                        'status' => HandoverStatus::PENDING,
                        'manager_approval_status' => 'pending',
                    ]
                );
                $shift->refresh();
                $handoverStatus = $shift->handoverStatus;
            }

            if (!$handoverStatus->canBeRejected()) {
                throw HandoverException::cannotBeRejected($handoverStatus->manager_approval_status);
            }

            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'rejection_' . $shift->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                $uploadedFiles[] = $path;
            }

            $result = $handoverStatus->reject($reviewerId, $reviewerType, $reason, $uploadedFiles, $comment);

            CashierShiftHandover::where('cashier_shift_id', $shift->id)
                ->update([
                    'status' => $result['is_final_rejection'] ? 'rejected_final' : 'rejected',
                    'rejection_reason' => $reason,
                    'rejection_count' => $result['rejection_count'],
                ]);

            if (!$result['is_final_rejection']) {
                $this->revertCashierShiftAfterHandoverRejection($shift->fresh(), [
                    'rejection_reason' => $reason,
                    'manager_comment' => $comment,
                    'reviewed_by_id' => $reviewerId,
                    'reviewed_by_type' => $reviewerType,
                    'rejection_files' => $uploadedFiles,
                    'source' => 'branch_manager_reject',
                ]);
            } else {
                $shift->recordHistory(
                    ShiftHistoryAction::HANDOVER_REJECTED->value,
                    ['status' => $handoverStatus->status->value],
                    [
                        'status' => HandoverStatus::REJECTED->value,
                        'manager_approval_status' => 'rejected_final',
                        'reviewed_by_id' => $reviewerId,
                        'reviewed_by_type' => $reviewerType,
                        'rejection_reason' => $reason,
                        'rejection_count' => $result['rejection_count'],
                        'is_final_rejection' => true,
                    ]
                );
            }

            DB::commit();

            $this->clearBranchManagerShiftCacheForApproval($shift, $reviewerId);

            return [
                'shift_id' => $shift->id,
                'handover_status' => $result['is_final_rejection'] ? 'rejected_final' : 'reverted',
                'rejection_count' => $result['rejection_count'],
                'is_final_rejection' => $result['is_final_rejection'],
                'can_cashier_edit' => $result['can_cashier_edit'],
                'rejection_reason' => $reason,
                'rejected_at' => now()->format(self::DATETIME_FORMAT),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Edit handover after rejection (by Cashier)
     *
     * Business Rule: If cashier edits rejected handover → Can be re-approved or rejected again
     *
     * @param CashierShift $shift
     * @param array $data
     * @return CashierShift
     */
    public function recordHandoverEdit(CashierShift $shift, array $data): CashierShift
    {
        DB::beginTransaction();
        try {
            $handoverStatus = $shift->handoverStatus;

            if (!$handoverStatus->canCashierEdit()) {
                throw HandoverException::cannotBeEdited();
            }

            // Update shift data
            $shift->update([
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? $shift->handover_notes,
            ]);

            // Recalculate variance using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            $variance = $shift->calculateVariance();
            $expectedBalance = $shift->total_sales;
            $shift->update([
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            // Update CashierShiftHandover
            CashierShiftHandover::where('cashier_shift_id', $shift->id)
                ->update([
                    'handover_amount' => $data['handover_amount'],
                    'variance_amount' => $variance,
                    'handover_notes' => $data['handover_notes'] ?? null,
                    'status' => 'pending',
                ]);

            // Mark as edited using model method
            $handoverStatus->markAsEdited();

            // Record history
            $shift->recordHistory(
                'handover_edited_after_rejection',
                ['status' => 'rejected'],
                [
                    'status' => 'pending',
                    'handover_amount' => $data['handover_amount'],
                    'variance' => $variance,
                    'rejection_count' => $handoverStatus->rejection_count,
                ]
            );

            DB::commit();
            return $shift->fresh(['handoverStatus', 'nextCashier']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Accept a handover (by receiving Cashier)
     *
     * Used when next cashier accepts the handover from previous cashier
     *
     * @param CashierShift $shift
     * @param string $cashierId
     * @param string|null $comment
     * @return void
     */
    public function acceptHandoverByCashier(
        CashierShift $shift,
        string $cashierId,
        ?string $comment = null
    ): void {
        DB::beginTransaction();
        try {
            // Verify this cashier is the recipient (from shift or handover record)
            $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->first();
            $isRecipient = $shift->next_cashier_id === $cashierId
                || ($handover && $handover->handover_to_type === 'cashier' && $handover->handover_to_id === $cashierId);
            if (!$isRecipient) {
                throw HandoverException::notAuthorizedToAccept();
            }

            if (!$shift->handoverStatus) {
                ShiftHandoverStatus::updateOrCreate(
                    ['cashier_shift_id' => $shift->id],
                    [
                        'status' => HandoverStatus::PENDING,
                        'manager_approval_status' => 'pending',
                    ]
                );
                $shift->refresh();
            }

            $shift->handoverStatus->approve(
                $cashierId,
                \Modules\Cashier\Models\Cashier::class,
                $comment
            );

            $shift->update(['status' => ShiftStatus::COMPLETED]);

            if ($handover) {
                $handover->update([
                    'status' => 'approved',
                    'approved_by_id' => $cashierId,
                    'approved_by_type' => \Modules\Cashier\Models\Cashier::class,
                    'approved_at' => now(),
                ]);
            }

            // Update next cashier's shift with opening balance
            $nextShift = CashierShift::where('cashier_id', $cashierId)
                ->where('shift_date', $shift->shift_date)
                ->where('status', ShiftStatus::NOT_STARTED)
                ->first();

            if ($nextShift) {
                $nextShift->update([
                    'opening_balance' => $shift->closing_balance,
                ]);
            }

            $shift->recordHistory(
                ShiftHistoryAction::HANDOVER_ACCEPTED->value,
                ['status' => HandoverStatus::PENDING->value],
                [
                    'status' => HandoverStatus::ACCEPTED->value,
                    'reviewed_by_id' => $cashierId,
                    'reviewed_by_type' => 'cashier',
                    'cashier_shift_status' => ShiftStatus::COMPLETED->value,
                ]
            );

            DB::commit();

            // Record custody transactions (both sides) only after successful acceptance
            try {
                $handover = CashierShiftHandover::with('cashierShift.cashier')
                    ->where('cashier_shift_id', $shift->id)
                    ->first();

                if (!$handover) {
                    Log::error('Custody entries skipped: handover record not found after accept', [
                        'shift_id' => $shift->id,
                    ]);
                    return;
                }

                $custodyService = app(\Modules\Custody\Services\CashierCustodyService::class);

                // Cash-OUT for the sending cashier
                $sendingCashier = $handover->cashierShift?->cashier;
                if ($sendingCashier) {
                    $custodyService->recordHandoverSent($handover, $sendingCashier);
                }

                // Cash-IN for the receiving cashier
                $receivingCashier = \Modules\Cashier\Models\Cashier::find($cashierId);
                if ($receivingCashier) {
                    $custodyService->recordHandoverReceived($handover, $receivingCashier);
                }

                Log::info('Custody entries created on handover accept', [
                    'shift_id'     => $shift->id,
                    'handover_id'  => $handover->id,
                    'sender_id'    => $sendingCashier?->id,
                    'receiver_id'  => $cashierId,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create custody entries on handover accept', [
                    'error'      => $e->getMessage(),
                    'cashier_id' => $cashierId,
                    'shift_id'   => $shift->id,
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reject a handover (by receiving Cashier)
     *
     * @param CashierShift $shift
     * @param string $cashierId
     * @param string $reason
     * @param array $files
     * @return void
     */
    public function rejectHandoverByCashier(
        CashierShift $shift,
        string $cashierId,
        string $reason,
        array $files = []
    ): void {
        DB::beginTransaction();
        try {
            // Verify this cashier is the recipient (from shift or handover record)
            $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->first();
            $isRecipient = $shift->next_cashier_id === $cashierId
                || ($handover && $handover->handover_to_type === 'cashier' && $handover->handover_to_id === $cashierId);
            if (!$isRecipient) {
                throw HandoverException::notAuthorizedToReject();
            }

            // Upload rejection files
            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'cashier_rejection_' . $shift->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                $uploadedFiles[] = $path;
            }

            $this->revertCashierShiftAfterHandoverRejection($shift->fresh(), [
                'rejection_reason' => $reason,
                'rejection_files' => $uploadedFiles,
                'reviewed_by_id' => $cashierId,
                'reviewed_by_type' => \Modules\Cashier\Models\Cashier::class,
                'source' => 'recipient_cashier_reject',
            ]);

            DB::commit();

            $this->clearBranchManagerShiftCachesForBranch($shift->fresh(['shift']));
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Accept a reassigned shift (by the cashier the shift was reassigned to).
     * Used when manager reassigns a shift and the new cashier accepts.
     *
     * @param CashierShift $shift
     * @param string $cashierId
     * @return CashierShift
     */
    public function acceptReassignedShift(CashierShift $shift, string $cashierId): CashierShift
    {
        DB::beginTransaction();
        try {
            if ($shift->status !== ShiftStatus::REASSIGNED) {
                throw HandoverException::notInReassignedStatus();
            }
            if ($shift->cashier_id !== $cashierId) {
                throw HandoverException::notAuthorizedForReassignment();
            }

            // Reassign without handover: no handover record; treat as already accepted.
            if (!$shift->handoverStatus) {
                $shift->update(['status' => ShiftStatus::NOT_STARTED]);
                $shift->recordHistory(
                    'reassigned_shift_accepted_without_handover',
                    ['status' => ShiftStatus::REASSIGNED->value],
                    ['status' => ShiftStatus::NOT_STARTED->value, 'accepted_by_cashier_id' => $cashierId]
                );
                DB::commit();
                return $shift->fresh(['cashier', 'shift', 'originalCashier', 'reassignedBy']);
            }

            if (($shift->handoverStatus->manager_approval_status ?? '') !== 'pending') {
                throw HandoverException::reassignedShiftNotPending();
            }

            $shift->handoverStatus->approve(
                $cashierId,
                \Modules\Cashier\Models\Cashier::class,
                null
            );

            // Transition shift status back to not_started so it appears in pending shifts
            $shift->update(['status' => ShiftStatus::NOT_STARTED]);

            $shift->recordHistory(
                'reassigned_shift_accepted',
                ['manager_approval_status' => 'pending', 'status' => ShiftStatus::REASSIGNED->value],
                [
                    'manager_approval_status' => 'approved',
                    'status' => ShiftStatus::NOT_STARTED->value,
                    'reviewed_by_id' => $cashierId,
                    'reviewed_by_type' => 'cashier',
                ]
            );

            DB::commit();
            return $shift->fresh(['handoverStatus.reviewedBy', 'cashier', 'shift', 'originalCashier', 'reassignedBy']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept reassigned shift', [
                'shift_id' => $shift->id,
                'cashier_id' => $cashierId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Reject a reassigned shift (by the cashier the shift was reassigned to).
     * Reverts the shift back to the original cashier with status in_progress.
     *
     * @param CashierShift $shift
     * @param string $cashierId
     * @param string $reason
     * @param array $files
     * @return CashierShift
     */
    public function rejectReassignedShift(
        CashierShift $shift,
        string $cashierId,
        string $reason,
        array $files = []
    ): CashierShift {
        DB::beginTransaction();
        try {
            if ($shift->status !== ShiftStatus::REASSIGNED) {
                throw HandoverException::notInReassignedStatus();
            }
            if ($shift->cashier_id !== $cashierId) {
                throw HandoverException::notAuthorizedForReassignment();
            }
            if (!$shift->handoverStatus) {
                throw HandoverException::rejectionOnlyForHandoverReassignment();
            }
            if (($shift->handoverStatus->manager_approval_status ?? '') !== 'pending') {
                throw HandoverException::reassignedShiftNotPending();
            }
            if (!$shift->original_cashier_id) {
                throw HandoverException::noOriginalCashier();
            }

            $uploadedFiles = [];
            foreach ($files as $file) {
                $path = $file->storeAs(
                    'handover_rejections/reassign',
                    'reassign_reject_' . $shift->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension(),
                    'public'
                );
                $uploadedFiles[] = $path;
            }

            $shift->handoverStatus->update([
                'status' => HandoverStatus::REJECTED,
                'manager_approval_status' => 'rejected',
                'reviewed_by_id' => $cashierId,
                'reviewed_by_type' => \Modules\Cashier\Models\Cashier::class,
                'rejection_reason' => $reason,
                'rejection_files' => !empty($uploadedFiles) ? array_merge($shift->handoverStatus->rejection_files ?? [], $uploadedFiles) : ($shift->handoverStatus->rejection_files ?? null),
                'reviewed_at' => now(),
            ]);

            $shift->update([
                'cashier_id' => $shift->original_cashier_id,
                'status' => ShiftStatus::IN_PROGRESS,
            ]);

            $shift->recordHistory(
                'reassigned_shift_rejected',
                [
                    'cashier_id' => $cashierId,
                    'status' => ShiftStatus::REASSIGNED->value,
                ],
                [
                    'cashier_id' => $shift->original_cashier_id,
                    'status' => ShiftStatus::IN_PROGRESS->value,
                    'reviewed_by_id' => $cashierId,
                    'rejection_reason' => $reason,
                ]
            );

            DB::commit();
            return $shift->fresh(['handoverStatus.reviewedBy', 'cashier', 'shift', 'originalCashier', 'reassignedBy']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject reassigned shift', [
                'shift_id' => $shift->id,
                'cashier_id' => $cashierId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Automatically hand over to the next scheduled shift
     *
     * Business Rule: System automatically ensures handover from Cashier 1 to Cashier 2
     * when shifts are consecutive (e.g., 9 AM – 6 PM → 6 PM – 12 AM)
     *
     * @param CashierShift $endedShift
     * @return CashierShift|null
     */
    public function autoHandover(CashierShift $endedShift): ?CashierShift
    {
        try {
            $nextShift = $this->resolveNextShiftForAutoHandover($endedShift);
            if ($nextShift === null) {
                return null;
            }

            $handoverAmount = $endedShift->closing_balance ?? $endedShift->total_sales ?? 0;

            $recorded = $this->recordHandover($endedShift, [
                'handover_to_type' => 'cashier',
                'handover_to_id'   => $nextShift->cashier_id,
                'next_cashier_id'  => $nextShift->cashier_id,
                'handover_amount'  => $handoverAmount,
                'handover_notes'   => 'Auto handover executed by system',
            ]);

            $nextShift->update(['opening_balance' => $handoverAmount]);

            Log::info('Auto handover completed successfully', [
                'shift_id'        => $endedShift->id,
                'next_shift_id'   => $nextShift->id,
                'next_cashier_id' => $nextShift->cashier_id,
                'handover_amount' => $handoverAmount,
            ]);

            return $recorded;
        } catch (\Exception $e) {
            Log::error('Auto handover failed', [
                'shift_id' => $endedShift->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Map a variance float to a human-readable type label.
     */
    private function resolveVarianceType(float $variance): string
    {
        if ($variance > 0) {
            return 'Over';
        }
        return $variance < 0 ? 'Short' : 'None';
    }

    /**
     * Resolve the next consecutive shift eligible for auto-handover, or return null.
     * Returns null (with logging) when already handed over or when no next shift exists.
     */
    private function resolveNextShiftForAutoHandover(CashierShift $endedShift): ?CashierShift
    {
        if ($endedShift->handoverStatus?->status === HandoverStatus::ACCEPTED) {
            Log::warning('Auto handover skipped: shift already handed over', ['shift_id' => $endedShift->id]);
            return null;
        }

        $nextShift = CashierShift::where('shift_date', $endedShift->shift_date)
            ->whereHas('shift', function ($q) use ($endedShift) {
                $q->where('branch_id', $endedShift->shift->branch_id)
                    ->where('start_time', '>=', $endedShift->shift->end_time);
            })
            ->where('status', ShiftStatus::NOT_STARTED)
            ->orderBy('shift_id')
            ->first();

        if (!$nextShift) {
            Log::info('No next shift found for auto handover', ['shift_id' => $endedShift->id]);
        }

        return $nextShift;
    }

    /**
     * Get handover summaries with statistics
     *
     * @param array $filters
     * @return array
     */
    public function getHandoverSummaries(array $filters = []): array
    {
        try {
            $query = CashierShift::with([
                'handoverStatus',
                'cashier',
                'nextCashier',
                'shift.branch'
            ])->whereHas('handoverStatus');

            // Apply filters
            if (!empty($filters['branch_id'])) {
                $query->whereHas('shift', function ($q) use ($filters) {
                    $q->where('branch_id', $filters['branch_id']);
                });
            }

            if (!empty($filters['date_from'])) {
                $query->where('shift_date', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->where('shift_date', '<=', $filters['date_to']);
            }

            if (!empty($filters['status'])) {
                $query->whereHas('handoverStatus', function ($q) use ($filters) {
                    $q->where('manager_approval_status', $filters['status']);
                });
            }

            if (!empty($filters['cashier_id'])) {
                $query->where('cashier_id', $filters['cashier_id']);
            }

            $shifts = $query->limit(2000)->get();

            // Calculate statistics
            $totalHandovers = $shifts->count();
            $pendingHandovers = $shifts->filter(fn($s) => $s->handoverStatus?->manager_approval_status === 'pending')->count();
            $acceptedHandovers = $shifts->filter(fn($s) => $s->handoverStatus?->manager_approval_status === 'approved')->count();
            $rejectedHandovers = $shifts->filter(fn($s) => in_array($s->handoverStatus?->manager_approval_status, ['rejected', 'rejected_final']))->count();
            $finalRejectedHandovers = $shifts->filter(fn($s) => $s->handoverStatus?->manager_approval_status === 'rejected_final')->count();

            // Variance statistics
            $totalVariance = $shifts->sum('variance');
            $avgVariance = $totalHandovers > 0 ? $shifts->avg('variance') : 0;

            $overages = $shifts->filter(fn($s) => $s->variance > 0);
            $shortages = $shifts->filter(fn($s) => $s->variance < 0);

            $totalOverage = $overages->sum('variance');
            $totalShortage = abs($shortages->sum('variance'));

            // Financial amounts
            $totalHandoverAmount = $shifts->sum('closing_balance');
            $totalExpectedAmount = $shifts->sum('expected_balance');

            // Recent handovers (last 10)
            $recentHandovers = $shifts->sortByDesc('handed_over_at')
                ->take(10)
                ->map(function ($shift) {
                    return [
                        'shift_id' => $shift->id,
                        'shift_date' => $shift->shift_date->format('Y-m-d'),
                        'cashier_name' => $shift->cashier?->name,
                        'next_cashier_name' => $shift->nextCashier?->name,
                        'handover_amount' => (float) $shift->closing_balance,
                        'variance'        => (float) $shift->variance,
                        'variance_type'   => $this->resolveVarianceType((float) $shift->variance),
                        'status'          => $shift->handoverStatus?->manager_approval_status,
                        'rejection_count' => $shift->handoverStatus?->rejection_count ?? 0,
                        'handed_over_at'  => $shift->handed_over_at?->format(self::DATETIME_FORMAT),
                        'branch_name'     => $shift->shift?->branch?->name,
                    ];
                })
                ->values();

            return [
                'overview' => [
                    'total_handovers' => $totalHandovers,
                    'pending' => $pendingHandovers,
                    'approved' => $acceptedHandovers,
                    'rejected' => $rejectedHandovers,
                    'rejected_final' => $finalRejectedHandovers,
                    'acceptance_rate' => $totalHandovers > 0 ? round(($acceptedHandovers / $totalHandovers) * 100, 2) : 0,
                    'rejection_rate' => $totalHandovers > 0 ? round(($rejectedHandovers / $totalHandovers) * 100, 2) : 0,
                ],
                'financial_summary' => [
                    'total_handover_amount' => (float) $totalHandoverAmount,
                    'total_expected_amount' => (float) $totalExpectedAmount,
                    'total_variance' => (float) $totalVariance,
                    'average_variance' => (float) round($avgVariance, 2),
                    'total_overage' => (float) $totalOverage,
                    'total_shortage' => (float) $totalShortage,
                    'variance_breakdown' => [
                        'overages_count' => $overages->count(),
                        'shortages_count' => $shortages->count(),
                        'exact_matches' => $shifts->filter(fn($s) => $s->variance == 0)->count(),
                    ],
                ],
                'recent_handovers' => $recentHandovers,
                'filters_applied' => $filters,
                'generated_at' => now()->format(self::DATETIME_FORMAT),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate handover summaries', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            throw $e;
        }
    }

    /**
     * Upload variance files
     *
     * @param array $files
     * @param string $shiftId
     * @return array
     */
    private function uploadVarianceFiles(array $files, string $shiftId): array
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            $filename = 'variance_' . $shiftId . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('variance/files', $filename, 'public');
            $uploadedFiles[] = $path;
        }

        return $uploadedFiles;
    }
}
