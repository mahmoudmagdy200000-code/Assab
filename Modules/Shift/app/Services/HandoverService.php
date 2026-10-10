<?php

namespace Modules\Shift\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftHistoryAction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Exceptions\HandoverException;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    public function __construct(
        private ShiftReportRevisionService $revisions,
        private ShiftTransferReceiptService $receipts,
        private ShiftCashCountService $cashCounts,
        private CountedReassignmentGuard $reassignmentGuard,
        private ShiftReportRevisionSnapshotService $snapshots,
        private VarianceCalculationService $varianceService
    ) {}

    /**
     * Record a new handover
     * Supports both cashier-to-cashier and cashier-to-manager handovers
     */
    public function recordHandover(CashierShift $shift, array $data, ?Model $actor = null): CashierShift
    {
        // Stage object storage before the source-shift lock/financial transaction.
        $varianceFiles = null;
        if (! empty($data['variance_files'])) {
            $varianceFiles = $this->uploadVarianceFiles($data['variance_files'], $shift->id);
        }

        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->reassignmentGuard->assertCanContinue($shift, $actor ?? auth()->user());
            $existingHandover = CashierShiftHandover::query()
                ->active()
                ->where('cashier_shift_id', $shift->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $this->assertNoCorrectionPending($shift);
            $handoverToType = $data['handover_to_type'] ?? 'cashier';
            $handoverToId = $data['handover_to_id'] ?? $data['next_cashier_id'] ?? null;

            if ($existingHandover) {
                if ($existingHandover->handover_to_type !== $handoverToType || (string) $existingHandover->handover_to_id !== (string) $handoverToId) {
                    throw new ConflictHttpException('HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT');
                }
                if ($existingHandover->status === 'pending') {
                    $latestRejection = $shift->history()
                        ->whereIn('action', ['handover_rejected_shift_reverted', 'handover_amount_correction_rejected'])
                        ->latest('id')
                        ->first();
                    $isCurrentlyAwaitingCorrection = $latestRejection
                        && ! $shift->history()->where('id', '>', $latestRejection->id)->whereIn('action', ['handover_edited_after_rejection', 'handover_recorded'])->exists();

                    if (! $isCurrentlyAwaitingCorrection) {
                        throw new ConflictHttpException('HANDOVER_ALREADY_PENDING');
                    }
                }
                if ($existingHandover->receipt()->exists()) {
                    throw new ConflictHttpException('CONFIRMED_RECEIPT_IMMUTABLE');
                }
            }

            Log::info('Recording handover', [
                'shift_id' => $shift->id,
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
            ]);

            $actor ??= auth()->user();
            $previousReportProjection = $shift->only(['closing_balance', 'handover_notes']);
            $previousRevision = $this->revisions->currentCashierRevision($shift);
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

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

            // Updating the submitted report's handover/closing projection advances
            // its stable identity. The request is bound to this exact revision.
            $revision = $this->revisions->recordCashierRevision(
                $shift,
                $actor instanceof BranchManager ? 'branch_manager' : 'cashier',
                (string) ($actor?->getKey() ?? $shift->cashier_id),
                $previousRevision?->revision_number ?? 0
            );
            if ($previousRevision) {
                // Sales and the physical count are unchanged by recording the handover request.
                $this->cashCounts->carryForward($previousRevision, $revision);
            }

            if (! empty($data['variance']) && $shift->hasVariance()) {
                $this->varianceService->recordVariance($shift, $data['variance']);
            }

            // Create CashierShiftHandover request; request creation is not receipt.
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
                'report_revision_id' => $revision->id,
            ];

            Log::info('Creating CashierShiftHandover', [
                'handover_data' => $handoverData,
            ]);

            if ($existingHandover) {
                // Older immutable snapshots can lack request narrative fields. Keep
                // the complete submitted request in additive history before replacing it.
                $this->writeCorrectionHistory($shift, 'handover_request_corrected',
                    (string) ($actor?->getKey() ?? $shift->cashier_id),
                    $actor?->getMorphClass() ?? 'cashier', [
                        'handover_id' => $existingHandover->id,
                        'old_report_revision_id' => $existingHandover->report_revision_id,
                        'new_report_revision_id' => $revision->id,
                        'previous_handover' => $existingHandover->toArray(),
                        'previous_report_projection' => $previousReportProjection,
                        'new_requested_amount' => (string) $data['handover_amount'],
                    ], 'Handover request resubmitted after rejection; original request evidence preserved.');
                $existingHandover->update($handoverData);
                $handover = $existingHandover;
            } else {
                $handover = CashierShiftHandover::create($handoverData);
            }

            if ($handover->report_revision_id !== $revision->id) {
                $handover->update(['report_revision_id' => $revision->id]);
            }

            $this->snapshots->createSnapshotIfMissing($revision, $shift);

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

            // Clear cache for branch manager shift so workday/current shows new handover immediately.
            // After commit: an enclosing command transaction must not let a poll re-cache stale data.
            if ($handoverToType === 'branch_manager' && $handoverToId) {
                DB::afterCommit(function () use ($handoverToId, $handover, $shift): void {
                    try {
                        $branchManagerShift = BranchManagerShift::where('branch_manager_id', $handoverToId)
                            ->whereDate('shift_date', $handover->handover_date ?? $shift->shift_date)
                            ->first();

                        if ($branchManagerShift) {
                            app(BranchManagerShiftService::class)->clearShiftCaches($branchManagerShift);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Failed to clear cache after handover', [
                            'error' => $e->getMessage(),
                            'handover_id' => $handover->id,
                        ]);
                    }
                });
            }

            Log::info('Handover recorded successfully', [
                'shift_id' => $shift->id,
                'handover_id' => $handover->id,
                'handover_to_type' => $handoverToType,
                'handover_to_id' => $handoverToId,
            ]);

            return $shift->fresh(['nextCashier', 'handoverStatus']);
        } catch (\Throwable $e) {
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
     */
    public function approveHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        ?string $managerComment = null,
        ?string $confirmedAmount = null,
        ?string $expectedAttemptId = null
    ): CashierShift {
        $handoverStub = $this->currentHandover($shift);

        if ($handoverStub->handover_to_type === 'branch_manager') {
            if ((string) $handoverStub->handover_to_id !== $reviewerId || $confirmedAmount === null) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('ONLY_ADDRESSED_MANAGER_MAY_CONFIRM_RECEIPT');
            }
            $manager = \Modules\BranchManagers\Models\BranchManager::query()->findOrFail($reviewerId);
            $this->receipts->confirmManagerHandover($handoverStub->id, $manager, $confirmedAmount, $managerComment, $expectedAttemptId);
            DB::afterCommit(fn () => $this->clearBranchManagerShiftCacheForApproval($shift, $reviewerId));

            return $shift->fresh([
                'handoverStatus.reviewedBy', 'nextCashier', 'cashier', 'shift', 'salesBreakdown.aggregator',
                'varianceDetails.responsibleCashier',
            ]);
        }

        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $handover = $this->currentHandover($shift, true);
            // Ensure handoverStatus exists
            $handoverStatus = ShiftHandoverStatus::query()
                ->where('cashier_shift_id', $shift->id)
                ->lockForUpdate()
                ->first();
            if (! $handoverStatus) {
                $handoverStatus = ShiftHandoverStatus::create([
                    'cashier_shift_id' => $shift->id,
                    'status' => HandoverStatus::PENDING,
                    'manager_approval_status' => 'pending',
                ]);
            }

            if ($handover->status !== 'pending') {
                throw new ConflictHttpException('HANDOVER_NOT_PENDING');
            }
            if ($shift->status === ShiftStatus::IN_PROGRESS) {
                throw new ConflictHttpException('REPORT_COUNT_REQUIRED');
            }
            $currentRevision = $this->revisions->currentCashierRevision($shift);
            if (! $currentRevision || ! \Modules\Shift\Models\ShiftReportCashCount::where('report_revision_id', $currentRevision->id)->exists()) {
                throw new ConflictHttpException('REPORT_COUNT_REQUIRED');
            }

            // Manager review is an audit observation only. The named cashier
            // recipient remains responsible for the confirmation receipt.
            $history = new \Modules\Shift\Models\CashierShiftHistory;
            $history->forceFill([
                'cashier_shift_id' => $shift->id,
                'action' => 'manager_handover_reviewed',
                'performed_by' => $reviewerId,
                'performed_by_type' => 'branch_manager',
                'old_value' => null,
                'new_value' => json_encode(['handover_id' => $handover->id, 'request_status' => 'pending']),
                'notes' => $managerComment,
                'created_at' => now(),
            ])->save();

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

            return $shift;
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        try {
            $shift->loadMissing('shift');
            $branchId = $shift->shift?->branch_id;
            if (! $branchId) {
                return;
            }

            $managerIds = BranchManager::where('branch_id', $branchId)->pluck('id');
            foreach ($managerIds as $managerId) {
                $this->clearBranchManagerShiftCacheForApproval($shift, (string) $managerId);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to clear branch manager shift caches after handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * D14: while a request rejected for an amount correction is unresolved (it has D11 evidence and no
     * receipt), no new request may be created for the shift. The sender corrects the same request.
     */
    public function assertNoCorrectionPending(CashierShift $shift): void
    {
        $this->revisions->assertFreshCount($shift);
        $rejected = CashierShiftHandover::query()
            ->active()
            ->where('cashier_shift_id', $shift->id)
            ->where('status', 'rejected')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->pluck('id');
        if ($rejected->isEmpty()) {
            return;
        }
        $withEvidence = ShiftTransferRejectionEvidence::query()
            ->whereIn('cashier_shift_handover_id', $rejected)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->pluck('cashier_shift_handover_id')
            ->unique();
        if ($withEvidence->isEmpty()) {
            return;
        }
        $received = DB::table('cashier_shift_handover_receipts')
            ->whereIn('cashier_shift_handover_id', $withEvidence)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->pluck('cashier_shift_handover_id');
        if ($withEvidence->diff($received)->isNotEmpty()) {
            throw new ConflictHttpException('HANDOVER_CORRECTION_PENDING');
        }
    }

    /** D14: a plain rejection must not delete a request that carries D11 evidence. */
    private function assertNoCorrectionEvidence(?CashierShiftHandover $handover): void
    {
        if ($handover && ShiftTransferRejectionEvidence::query()->where('cashier_shift_handover_id', $handover->id)->exists()) {
            throw new ConflictHttpException('HANDOVER_HAS_CORRECTION_EVIDENCE');
        }
    }

    /**
     * After a handover rejection that allows the cashier to redo end-shift: reset shift data, strip custody, remove handover rows.
     */
    public function revertCashierShiftAfterHandoverRejection(CashierShift $shift, array $audit = []): void
    {
        $shift = $shift->fresh();

        $previousRevision = $this->revisions->currentCashierRevision($shift);
        if ($previousRevision) {
            $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
        }

        // D18: the rejected report is no longer the current report. A new revision without a count
        // makes the liability evidence unavailable (fail closed) until the cashier re-ends the shift.
        // Previous revisions, counts and allocations stay as immutable history.
        $reviewerIsCashier = ($audit['reviewed_by_type'] ?? null) === \Modules\Cashier\Models\Cashier::class;
        $this->revisions->recordCashierRevision(
            $shift,
            $reviewerIsCashier || ! isset($audit['reviewed_by_id']) ? 'cashier' : 'branch_manager',
            isset($audit['reviewed_by_id']) ? (string) $audit['reviewed_by_id'] : (string) $shift->cashier_id
        );

        if ($audit !== []) {
            $shift->recordHistory(
                'handover_rejected_shift_reverted',
                ['status' => $shift->status->value],
                array_merge([
                    'status' => ShiftStatus::IN_PROGRESS->value,
                ], $audit)
            );
        }

        $shift->update([
            'status' => ShiftStatus::IN_PROGRESS,
        ]);
    }

    /**
     * Reject a handover (by Branch Manager)
     *
     * Business Rules:
     * - First rejection: Cashier can edit and resubmit
     * - Second rejection: Status permanently changes to 'rejected_final'
     */
    public function rejectHandover(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        string $reason,
        array $files = [],
        ?string $comment = null
    ): array {
        $uploadedFiles = [];
        $committed = false;
        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $handover = $this->currentHandover($shift, true);
            $this->assertAddressedRecipient($handover, $shift, $reviewerId, $reviewerType);
            $handoverStatus = ShiftHandoverStatus::query()
                ->where('cashier_shift_id', $shift->id)
                ->lockForUpdate()
                ->first();

            if (! $handoverStatus) {
                $handoverStatus = ShiftHandoverStatus::create([
                    'cashier_shift_id' => $shift->id,
                    'status' => HandoverStatus::PENDING,
                    'manager_approval_status' => 'pending',
                ]);
            }

            if ($handover?->receipt()->exists()) {
                throw new ConflictHttpException('CONFIRMED_RECEIPT_IMMUTABLE');
            }
            if ($handover?->current_transfer_attempt_id) {
                throw new ConflictHttpException('PHYSICAL_REJECTION_DETAILS_REQUIRED');
            }
            if ($handover && $handover->status !== 'pending') {
                throw new ConflictHttpException('HANDOVER_NOT_PENDING');
            }
            if (! $handoverStatus->canBeRejected()) {
                throw HandoverException::cannotBeRejected($handoverStatus->manager_approval_status);
            }

            $this->assertNoCorrectionEvidence($handover);

            $previousRevision = $this->revisions->currentCashierRevision($shift);
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'rejection_'.$shift->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $uploadedFiles[] = 'handover_rejections/'.$filename;
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                if ($path === false) {
                    throw new \RuntimeException('Rejection evidence upload failed.');
                }
            }

            $result = $handoverStatus->reject($reviewerId, $reviewerType, $reason, $uploadedFiles, $comment);

            if ($handover) {
                $handover->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'rejection_count' => $result['rejection_count'],
                ]);
            }

            $this->revertCashierShiftAfterHandoverRejection($shift->fresh(), [
                'rejection_reason' => $reason,
                'manager_comment' => $comment,
                'reviewed_by_id' => $reviewerId,
                'reviewed_by_type' => $reviewerType,
                'rejection_files' => $uploadedFiles,
                'source' => 'branch_manager_reject',
            ]);

            DB::commit();
            $committed = true;

            $this->clearBranchManagerShiftCacheForApproval($shift, $reviewerId);

            return [
                'shift_id' => $shift->id,
                'handover_status' => 'reverted',
                'rejection_count' => $result['rejection_count'],
                'is_final_rejection' => false,
                'can_cashier_edit' => true,
                'rejection_reason' => $reason,
                'rejected_at' => now()->format(self::DATETIME_FORMAT),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            if (! $committed) {
                $this->discardRejectionAttemptFiles($uploadedFiles);
            }
            Log::error('Failed to reject handover', [
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function discardRejectionAttemptFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! Storage::disk('public')->delete($path)) {
                    Log::warning('Could not remove failed rejection upload', ['path' => $path]);
                }
            } catch (\Throwable $cleanupError) {
                Log::warning('Failed rejection upload cleanup failed', ['path' => $path, 'error' => $cleanupError->getMessage()]);
            }
        }
    }

    /**
     * Edit handover after rejection (by Cashier)
     *
     * Business Rule: If cashier edits rejected handover → Can be re-approved or rejected again
     */
    public function recordHandoverEdit(CashierShift $shift, array $data): CashierShift
    {
        $correctionReason = $data['correction_reason'] ?? null;
        if (! in_array($correctionReason, ['input_error', 'actual_shortage'], true)) {
            throw new \InvalidArgumentException('Correction reason must be input_error or actual_shortage.');
        }

        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $handover = $this->currentHandover($shift, true);
            TransferRequestLifecycleGuard::assertActive($handover);
            if (isset($data['handover_to_type']) && $data['handover_to_type'] !== $handover->handover_to_type) {
                throw new ConflictHttpException('HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT');
            }
            if (isset($data['handover_to_id']) && (string) $data['handover_to_id'] !== (string) $handover->handover_to_id) {
                throw new ConflictHttpException('HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT');
            }
            if ($handover->receipt()->exists()) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('CONFIRMED_RECEIPT_IMMUTABLE');
            }
            if (! in_array($handover->status, ['rejected', 'rejected_final'])) {
                throw new ConflictHttpException('HANDOVER_NOT_CORRECTABLE');
            }
            $handoverStatus = ShiftHandoverStatus::query()->where('cashier_shift_id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $handoverStatus->canCashierEdit()) {
                throw HandoverException::cannotBeEdited();
            }

            $previousRevision = $this->revisions->currentCashierRevision($shift);
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            // An unreceived legacy report may predate physical-count evidence. Reopen only
            // this correction path so the owner can explicitly end and count it again.
            $needsLegacyCount = $shift->status === ShiftStatus::COMPLETED
                && $handover->current_transfer_attempt_id === null
                && (! $previousRevision || ! \Modules\Shift\Models\ShiftReportCashCount::where('report_revision_id', $previousRevision->id)->exists());

            $previousReportProjection = $shift->only(['closing_balance', 'handover_notes']);

            // Update shift data
            $shift->update([
                'closing_balance' => $data['handover_amount'],
                'handover_notes' => $data['handover_notes'] ?? $shift->handover_notes,
                'status' => $needsLegacyCount ? ShiftStatus::IN_PROGRESS : $shift->status,
            ]);

            // Recalculate variance using the correct formula:
            // Variance = Total Sales - (Cash Collected + Card Payments + Delivery Apps)
            $variance = $shift->calculateVariance();
            $expectedBalance = $shift->total_sales;
            $shift->update([
                'expected_balance' => $expectedBalance,
                'variance' => $variance,
            ]);

            $revision = $this->revisions->recordCashierRevision($shift, 'cashier', $shift->cashier_id);
            if ($previousRevision) {
                $this->cashCounts->carryForward($previousRevision, $revision);
            }

            $actor = auth()->user();
            $this->writeCorrectionHistory($shift, 'handover_request_corrected', (string) ($actor?->id ?? '0'), $actor?->getMorphClass() ?? 'system', [
                'handover_id' => $handover->id,
                'previous_handover' => $handover->toArray(),
                'previous_report_projection' => $previousReportProjection,
                'old_requested_amount' => (string) $handover->handover_amount,
                'new_requested_amount' => (string) $data['handover_amount'],
                'correction_reason' => $correctionReason,
                'old_report_revision_id' => $handover->report_revision_id,
                'new_report_revision_id' => $revision->id,
                'variance_amount' => (string) $variance,
                'variance_reason' => $handover->variance_reason,
                'variance_files' => $handover->variance_files,
            ], $correctionReason === 'actual_shortage'
                ? 'Actual shortage reported; source evidence preserved as submitted for later trusted evidence review.'
                : 'Request amount corrected after identifying an input error.');

            // Update CashierShiftHandover
            $handover->update([
                'handover_amount' => $data['handover_amount'],
                'variance_amount' => $variance,
                'handover_notes' => $data['handover_notes'] ?? null,
                'status' => 'pending',
                'report_revision_id' => $revision->id,
            ]);

            $this->snapshots->createSnapshotIfMissing($revision, $shift);

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
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Accept a handover (by receiving Cashier)
     *
     * Used when next cashier accepts the handover from previous cashier
     */
    public function acceptHandoverByCashier(
        CashierShift $shift,
        string $cashierId,
        string $confirmedAmount,
        ?string $receivingShiftId = null,
        ?string $comment = null,
        ?Response $commandResponse = null,
        ?string $expectedAttemptId = null
    ): void {
        $handover = $this->currentHandover($shift);
        if ($handover->handover_to_type !== 'cashier' || (string) $handover->handover_to_id !== $cashierId) {
            throw HandoverException::notAuthorizedToAccept();
        }
        if ($handover->status !== 'pending') {
            throw new ConflictHttpException('HANDOVER_NOT_PENDING');
        }
        if ($shift->status === ShiftStatus::IN_PROGRESS) {
            throw new ConflictHttpException('REPORT_COUNT_REQUIRED');
        }

        $this->receipts->confirmHandover(
            $handover->id,
            \Modules\Cashier\Models\Cashier::findOrFail($cashierId),
            $confirmedAmount,
            $receivingShiftId,
            $comment,
            $commandResponse,
            $expectedAttemptId
        );
        $this->clearBranchManagerShiftCachesForBranch($shift);
    }

    /**
     * Reject a handover (by receiving Cashier)
     */
    public function rejectHandoverByCashier(
        CashierShift $shift,
        string $cashierId,
        string $reason,
        array $files = []
    ): void {
        $uploadedFiles = [];
        $committed = false;
        DB::beginTransaction();
        try {
            $shift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $handover = $this->currentHandover($shift, true);
            $handoverStatus = ShiftHandoverStatus::query()
                ->where('cashier_shift_id', $shift->id)
                ->lockForUpdate()
                ->first();
            if ($handover->handover_to_type !== 'cashier' || (string) $handover->handover_to_id !== $cashierId) {
                throw HandoverException::notAuthorizedToReject();
            }
            if ($handover->receipt()->exists()) {
                throw new ConflictHttpException('CONFIRMED_RECEIPT_IMMUTABLE');
            }
            if ($handover->current_transfer_attempt_id) {
                throw new ConflictHttpException('PHYSICAL_REJECTION_DETAILS_REQUIRED');
            }
            if ($handover->status !== 'pending' || ! $handoverStatus?->canBeAcceptedByCashier()) {
                throw new ConflictHttpException('HANDOVER_NOT_PENDING');
            }

            $this->assertNoCorrectionEvidence($handover);

            $previousRevision = $this->revisions->currentCashierRevision($shift);
            if ($previousRevision) {
                $this->snapshots->preserveVarianceReviews($shift, $previousRevision);
                $this->snapshots->createSnapshotIfMissing($previousRevision, $shift);
            }

            // Upload rejection files
            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'cashier_rejection_'.$shift->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $uploadedFiles[] = 'handover_rejections/'.$filename;
                $path = $file->storeAs('handover_rejections', $filename, 'public');
                if ($path === false) {
                    throw new \RuntimeException('Rejection evidence upload failed.');
                }
            }

            $rejectionResult = null;
            if ($handoverStatus) {
                $rejectionResult = $handoverStatus->reject(
                    $cashierId,
                    \Modules\Cashier\Models\Cashier::class,
                    $reason,
                    $uploadedFiles
                );
            }

            if ($handover) {
                $handover->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'rejection_count' => $rejectionResult['rejection_count'] ?? 1,
                ]);
            }

            $this->revertCashierShiftAfterHandoverRejection($shift, [
                'rejection_reason' => $reason,
                'rejection_files' => $uploadedFiles,
                'reviewed_by_id' => $cashierId,
                'reviewed_by_type' => \Modules\Cashier\Models\Cashier::class,
                'source' => 'recipient_cashier_reject',
            ]);

            DB::commit();
            $committed = true;

            $this->clearBranchManagerShiftCachesForBranch($shift->fresh(['shift']));
        } catch (\Throwable $e) {
            DB::rollBack();
            if (! $committed) {
                $this->discardRejectionAttemptFiles($uploadedFiles);
            }
            throw $e;
        }
    }

    /** Reject a mismatched transfer amount without deleting its request or evidence. */
    public function rejectHandoverForAmountCorrection(
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType,
        string $reason,
        string $attemptedConfirmedAmount,
        string $correctionReason,
        ?string $expectedAttemptId = null
    ): void {
        if (! in_array($correctionReason, ['input_error', 'actual_shortage'], true)) {
            throw new \InvalidArgumentException('Correction reason must be input_error or actual_shortage.');
        }

        $attemptedMinor = self::toMinorUnits($attemptedConfirmedAmount);
        DB::transaction(function () use ($shift, $reviewerId, $reviewerType, $reason, $attemptedConfirmedAmount, $attemptedMinor, $correctionReason, $expectedAttemptId) {
            $lockedShift = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $handover = $this->currentHandover($lockedShift, true);
            $status = ShiftHandoverStatus::query()->where('cashier_shift_id', $lockedShift->id)->lockForUpdate()->firstOrFail();

            $this->assertAddressedRecipient($handover, $lockedShift, $reviewerId, $reviewerType);

            if ($handover->status !== 'pending' || $handover->receipt()->exists() || ! $status->canBeRejected()) {
                throw new ConflictHttpException('HANDOVER_NOT_CORRECTABLE');
            }

            $previousAmount = (string) $handover->handover_amount;
            if ($expectedAttemptId === null && $attemptedMinor === self::toMinorUnits($previousAmount)) {
                throw new ConflictHttpException('HANDOVER_AMOUNT_MISMATCH_REQUIRED_FOR_CORRECTION');
            }

            $previousRevision = $this->revisions->currentCashierRevision($lockedShift);
            if ($previousRevision) {
                $this->snapshots->createSnapshotIfMissing($previousRevision, $lockedShift);
            }

            $result = $status->reject($reviewerId, $reviewerType, $reason);
            $handover->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'rejection_count' => $result['rejection_count'],
            ]);

            // D11: structured physical amount at rejection, linked to the request. It is pending
            // incoming (sender stays responsible), never a surplus; the history JSON below is context only.
            $isManagerRecipient = $handover->handover_to_type === 'branch_manager';
            $this->cashCounts->recordRejectionEvidence(
                $handover->id,
                null,
                $isManagerRecipient ? 'branch_manager' : 'cashier',
                $reviewerId,
                $isManagerRecipient ? null : $this->cashCounts->resolveReceivingShiftId($lockedShift, $reviewerId),
                $previousAmount,
                $attemptedConfirmedAmount,
                $correctionReason,
                $expectedAttemptId,
            );

            $this->writeCorrectionHistory($lockedShift, 'handover_amount_correction_rejected', $reviewerId, $reviewerType, [
                'handover_id' => $handover->id,
                'requested_amount' => $previousAmount,
                'attempted_confirmed_amount' => $attemptedConfirmedAmount,
                'correction_reason' => $correctionReason,
                'rejection_reason' => $reason,
                'report_revision_id' => $handover->report_revision_id,
                'variance_amount' => (string) $handover->variance_amount,
                'variance_reason' => $handover->variance_reason,
                'variance_files' => $handover->variance_files,
            ], 'Recipient rejected because the confirmed physical amount differed from the request.');
        });
        $this->clearBranchManagerShiftCachesForBranch($shift);
    }

    private function writeCorrectionHistory(CashierShift $shift, string $action, string $actorId, string $actorType, array $details, ?string $notes): void
    {
        $normalizedActorType = str_contains($actorType, 'BranchManager') || $actorType === 'branch_manager'
            ? 'branch_manager'
            : (str_contains($actorType, 'Cashier') || $actorType === 'cashier' ? 'cashier' : 'system');
        $details['actor_id'] = $actorId;
        $details['actor_type'] = $normalizedActorType;

        $history = new \Modules\Shift\Models\CashierShiftHistory;
        $history->forceFill([
            'cashier_shift_id' => $shift->id,
            'action' => $action,
            // The later history migration stores the actual actor UUID here.
            'performed_by' => $actorId,
            'performed_by_type' => $normalizedActorType,
            'old_value' => null,
            'new_value' => $details,
            'notes' => $notes,
            'created_at' => now(),
        ])->save();
    }

    private static function toMinorUnits(string $amount): int
    {
        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount)) {
            throw new \InvalidArgumentException('Confirmed amount must be a nonnegative SAR value with at most two decimals.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function assertAddressedRecipient(
        CashierShiftHandover $handover,
        CashierShift $shift,
        string $reviewerId,
        string $reviewerType
    ): void {
        $isManagerReviewer = $reviewerType === 'branch_manager' || str_contains($reviewerType, 'BranchManager');

        if ($handover->handover_to_type !== 'branch_manager') {
            if ($isManagerReviewer) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('ONLY_ADDRESSED_RECIPIENT');
            }

            return;
        }

        if (! $isManagerReviewer || (string) $handover->handover_to_id !== (string) $reviewerId) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('ONLY_ADDRESSED_RECIPIENT');
        }

        app(\Modules\BranchManagers\Services\BranchManagerService::class)
            ->assertAssignedActiveManager($shift->shift()->value('branch_id'), $reviewerId);
    }

    /** The latest request is current; two concurrent pending rows are ambiguous. */
    public function currentHandover(CashierShift $shift, bool $lock = false): CashierShiftHandover
    {
        $query = CashierShiftHandover::query()
            ->active()
            ->where('cashier_shift_id', $shift->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        $current = $rows->first();
        if (! $current) {
            throw new ConflictHttpException('HANDOVER_NOT_PENDING');
        }
        if ($rows->count() > 1 && $current->status === 'pending' && $rows[1]->status === 'pending') {
            throw new ConflictHttpException('HANDOVER_CURRENT_REQUEST_AMBIGUOUS');
        }

        return $current;
    }

    /**
     * Accept a reassigned shift (by the cashier the shift was reassigned to).
     * Used when manager reassigns a shift and the new cashier accepts.
     */
    public function acceptReassignedShift(CashierShift $shift, string $cashierId): CashierShift
    {
        DB::beginTransaction();
        try {
            $shift = $this->lockReassignmentState($shift);
            if ($shift->status !== ShiftStatus::REASSIGNED) {
                throw HandoverException::notInReassignedStatus();
            }
            if ($shift->cashier_id !== $cashierId) {
                throw HandoverException::notAuthorizedForReassignment();
            }

            if ($shift->handoverStatus && $shift->handoverStatus->manager_approval_status !== 'pending') {
                throw HandoverException::reassignedShiftNotPending();
            }

            // Operational acceptance cannot transfer a predecessor report or confirm its cash.
            $shift = app(ReassignmentReportOwnershipService::class)->separate($shift);
            $shift = $this->lockReassignmentState($shift);

            // Reassign without handover: no handover record; treat as already accepted.
            if (! $shift->handoverStatus) {
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
     */
    public function rejectReassignedShift(
        CashierShift $shift,
        string $cashierId,
        string $reason,
        array $files = []
    ): CashierShift {
        $uploadedFiles = [];
        $committed = false;
        DB::beginTransaction();
        try {
            $shift = $this->lockReassignmentState($shift);
            if ($shift->status !== ShiftStatus::REASSIGNED) {
                throw HandoverException::notInReassignedStatus();
            }
            if ($shift->cashier_id !== $cashierId) {
                throw HandoverException::notAuthorizedForReassignment();
            }
            if (! $shift->handoverStatus) {
                throw HandoverException::rejectionOnlyForHandoverReassignment();
            }
            if (($shift->handoverStatus->manager_approval_status ?? '') !== 'pending') {
                throw HandoverException::reassignedShiftNotPending();
            }
            if (! $shift->original_cashier_id) {
                throw HandoverException::noOriginalCashier();
            }

            $uploadedFiles = [];
            foreach ($files as $file) {
                $filename = 'reassign_reject_'.$shift->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $uploadedFiles[] = 'handover_rejections/reassign/'.$filename;
                $path = $file->storeAs(
                    'handover_rejections/reassign',
                    $filename,
                    'public'
                );
                if ($path === false) {
                    throw new \RuntimeException('Rejection evidence upload failed.');
                }
            }

            $shift->handoverStatus->update([
                'status' => HandoverStatus::REJECTED,
                'manager_approval_status' => 'rejected',
                'reviewed_by_id' => $cashierId,
                'reviewed_by_type' => \Modules\Cashier\Models\Cashier::class,
                'rejection_reason' => $reason,
                'rejection_files' => ! empty($uploadedFiles) ? array_merge($shift->handoverStatus->rejection_files ?? [], $uploadedFiles) : ($shift->handoverStatus->rejection_files ?? null),
                'reviewed_at' => now(),
            ]);

            if (app(ReassignmentReportOwnershipService::class)->ownsIncomingReport($shift)) {
                // Rejecting work on an independent row leaves all predecessor financial evidence intact.
                $shift->update(['status' => ShiftStatus::CANCELED]);
                $shift->recordHistory('reassigned_shift_rejected', null, ['rejection_reason' => $reason]);
                DB::commit();
                $committed = true;

                return $shift->fresh(['handoverStatus.reviewedBy', 'cashier', 'shift', 'originalCashier', 'reassignedBy']);
            }

            $shift->update([
                'cashier_id' => $shift->original_cashier_id,
                'status' => ShiftStatus::IN_PROGRESS,
            ]);

            // D16/D18: the outgoing cashier's submitted report is no longer current once the shift
            // returns to them; a revision without a count keeps the evidence fail-closed until they re-end.
            if ($this->revisions->currentCashierRevision($shift) !== null) {
                $this->revisions->recordCashierRevision($shift, 'cashier', (string) $cashierId);
            }

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
            $committed = true;

            return $shift->fresh(['handoverStatus.reviewedBy', 'cashier', 'shift', 'originalCashier', 'reassignedBy']);
        } catch (\Throwable $e) {
            DB::rollBack();
            if (! $committed) {
                $this->discardRejectionAttemptFiles($uploadedFiles);
            }
            Log::error('Failed to reject reassigned shift', [
                'shift_id' => $shift->id,
                'cashier_id' => $cashierId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** Both transitions lock the owner first, then its approval row; never trust a preloaded relation. */
    private function lockReassignmentState(CashierShift $shift): CashierShift
    {
        $locked = CashierShift::withoutEagerLoads()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
        $status = ShiftHandoverStatus::query()->where('cashier_shift_id', $locked->id)->lockForUpdate()->first();
        $locked->setRelation('handoverStatus', $status);

        return $locked;
    }

    /**
     * Automatically hand over to the next scheduled shift
     *
     * Business Rule: System automatically ensures handover from Cashier 1 to Cashier 2
     * when shifts are consecutive (e.g., 9 AM – 6 PM → 6 PM – 12 AM)
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
                'handover_to_id' => $nextShift->cashier_id,
                'next_cashier_id' => $nextShift->cashier_id,
                'handover_amount' => $handoverAmount,
                'handover_notes' => 'Auto handover executed by system',
            ]);

            // This is only a request. The recipient's opening balance is
            // projected from confirmed receipts by ShiftTransferReceiptService.

            Log::info('Auto handover completed successfully', [
                'shift_id' => $endedShift->id,
                'next_shift_id' => $nextShift->id,
                'next_cashier_id' => $nextShift->cashier_id,
                'handover_amount' => $handoverAmount,
            ]);

            return $recorded;
        } catch (\Throwable $e) {
            Log::error('Auto handover failed', [
                'shift_id' => $endedShift->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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

        if (! $nextShift) {
            Log::info('No next shift found for auto handover', ['shift_id' => $endedShift->id]);
        }

        return $nextShift;
    }

    /**
     * Get handover summaries with statistics
     */
    public function getHandoverSummaries(array $filters = []): array
    {
        try {
            $query = CashierShift::with([
                'handoverStatus',
                'cashier',
                'nextCashier',
                'shift.branch',
            ])->whereHas('handoverStatus');

            // Apply filters
            if (! empty($filters['branch_id'])) {
                $query->whereHas('shift', function ($q) use ($filters) {
                    $q->where('branch_id', $filters['branch_id']);
                });
            }

            if (! empty($filters['date_from'])) {
                $query->where('shift_date', '>=', $filters['date_from']);
            }

            if (! empty($filters['date_to'])) {
                $query->where('shift_date', '<=', $filters['date_to']);
            }

            if (! empty($filters['status'])) {
                $query->whereHas('handoverStatus', function ($q) use ($filters) {
                    $q->where('manager_approval_status', $filters['status']);
                });
            }

            if (! empty($filters['cashier_id'])) {
                $query->where('cashier_id', $filters['cashier_id']);
            }

            $shifts = $query->limit(2000)->get();

            // Calculate statistics
            $totalHandovers = $shifts->count();
            $pendingHandovers = $shifts->filter(fn ($s) => $s->handoverStatus?->manager_approval_status === 'pending')->count();
            $acceptedHandovers = $shifts->filter(fn ($s) => $s->handoverStatus?->manager_approval_status === 'approved')->count();
            $rejectedHandovers = $shifts->filter(fn ($s) => in_array($s->handoverStatus?->manager_approval_status, ['rejected', 'rejected_final']))->count();
            $finalRejectedHandovers = $shifts->filter(fn ($s) => $s->handoverStatus?->manager_approval_status === 'rejected_final')->count();

            // Variance statistics
            $totalVariance = $shifts->sum('variance');
            $avgVariance = $totalHandovers > 0 ? $shifts->avg('variance') : 0;

            $overages = $shifts->filter(fn ($s) => $s->variance > 0);
            $shortages = $shifts->filter(fn ($s) => $s->variance < 0);

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
                        'variance' => (float) $shift->variance,
                        'variance_type' => $this->resolveVarianceType((float) $shift->variance),
                        'status' => $shift->handoverStatus?->manager_approval_status,
                        'rejection_count' => $shift->handoverStatus?->rejection_count ?? 0,
                        'handed_over_at' => $shift->handed_over_at?->format(self::DATETIME_FORMAT),
                        'branch_name' => $shift->shift?->branch?->name,
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
                        'exact_matches' => $shifts->filter(fn ($s) => $s->variance == 0)->count(),
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
     */
    private function uploadVarianceFiles(array $files, string $shiftId): array
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            if (is_string($file)) {
                $uploadedFiles[] = $file;

                continue;
            }

            $filename = 'variance_'.$shiftId.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('variance/files', $filename, 'public');
            $uploadedFiles[] = $path;
        }

        return $uploadedFiles;
    }

    /** Store handover attachments before a caller acquires financial locks. */
    public function stageVarianceFiles(array $files, string $shiftId): array
    {
        return $this->uploadVarianceFiles($files, $shiftId);
    }
}
