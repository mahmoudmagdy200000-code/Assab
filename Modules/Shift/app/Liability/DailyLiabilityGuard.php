<?php

namespace Modules\Shift\Liability;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DailyLiabilityGuard
{
    public function __construct(
        private LiabilityEvidenceSource $evidence,
        private ShiftLiabilityService $liabilities,
        private ResponsibleActorResolver $actors,
    ) {}

    /**
     * Must run inside the SAME transaction as daily-submit writes. No HTTP-supplied membership.
     * Not connected to the legacy submit endpoint until complete receipt/revision scope exists.
     */
    public function assertReady(string $workdayId, BranchManager $manager): void
    {
        $this->readyScope($workdayId, $manager);
    }

    /**
     * Assert readiness and lock the liability of every report in the submitted day, in the same
     * transaction as the daily-submit writes. After this, allocation, cashier confirmation and
     * manager approval for those reports are refused until the day is reopened (releaseDay).
     * Employee responses stay possible: an objection is recorded evidence and never blocks (BR-10).
     */
    public function lockSubmittedDay(string $workdayId, BranchManager $manager): void
    {
        $scope = $this->readyScope($workdayId, $manager);
        foreach ($scope->reports as $report) {
            // One lock row per (report, workday): a carried-over report stays locked while ANY day that
            // includes it is submitted. The workday and cashier-shift rows are already locked by
            // readyScope(), so these plain reads are consistent and take no MySQL gap locks.
            $alreadyLockedForThisDay = ShiftLiabilityDailyLock::active()
                ->where('cashier_shift_id', $report->shiftId)
                ->where('branch_manager_shift_id', $workdayId)
                ->exists();
            if ($alreadyLockedForThisDay) {
                continue;
            }
            ShiftLiabilityDailyLock::create([
                'cashier_shift_id' => $report->shiftId,
                'branch_manager_shift_id' => $workdayId,
                'report_revision' => $report->revision,
                'allocation_version' => ShiftLiabilityAllocation::where('cashier_shift_id', $report->shiftId)->lockForUpdate()->max('version'),
                'locked_by_id' => (string) $manager->getKey(),
                'locked_at' => now(),
            ]);
        }
    }

    /**
     * Reopen: release this workday's active locks with actor, time and reason. Rows are kept as history.
     * A report that another submitted day also includes stays locked by that day's row.
     */
    public function releaseDay(string $workdayId, BranchManager $manager, string $reason): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Releasing daily liability locks requires the reopen transaction.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Reopening a submitted day requires a reason.']);
        }
        $workday = app(\Modules\Shift\Services\ShiftReportReviewService::class)->lockDay(
            BranchManagerShift::withoutEagerLoads()->findOrFail($workdayId));
        if ($workday->branch_manager_id !== $manager->getKey()) {
            throw new AccessDeniedHttpException('ONLY_WORKDAY_MANAGER');
        }

        app(\Modules\Shift\Services\ShiftReportReviewService::class)->assertMutableDay($workday);
        return ShiftLiabilityDailyLock::active()->where('branch_manager_shift_id', $workdayId)->update([
            'released_by_id' => (string) $manager->getKey(),
            'released_at' => now(),
            'release_reason' => $reason,
            'reopened_at' => now(),
            'reopened_by_type' => 'branch_manager',
            'reopened_by_id' => (string) $manager->getKey(),
            'reopen_reason' => $reason,
        ]);
    }

    /**
     * Primitive for superseding active daily locks on a shift due to authorized revision/correction.
     */
    public function supersedeLocksForShift(string $shiftId, \Illuminate\Database\Eloquent\Model $actor, string $reason, ?string $workdayId = null): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Superseding daily liability locks requires a transaction.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Superseding daily locks requires a reason.']);
        }
        if (! $actor instanceof BranchManager) {
            throw new AccessDeniedHttpException('ONLY_WORKDAY_MANAGER');
        }
        $shift = app(\Modules\Shift\Services\ShiftReportReviewService::class)->lockCashier(CashierShift::withoutEagerLoads()->findOrFail($shiftId));
        app(\Modules\Shift\Services\ShiftReportReviewService::class)->assertMutableCashier($shift);
        $branchId = $shift->shift()->value('branch_id');
        app(\Modules\BranchManagers\Services\BranchManagerService::class)
            ->assertAssignedActiveManager($branchId, (string) $actor->id);
        if ($workdayId === null) {
            $ids = ShiftLiabilityDailyLock::active()->where('cashier_shift_id', $shiftId)
                ->distinct()->pluck('branch_manager_shift_id');
            if ($ids->count() > 1) {
                throw new ConflictHttpException('WORKDAY_SCOPE_REQUIRED');
            }
            $workdayId = $ids->first();
            if ($workdayId === null) {
                return 0;
            }
        }
        $day = BranchManagerShift::withoutEagerLoads()->whereKey($workdayId)->lockForUpdate()->firstOrFail();
        if ((string) $day->branch_manager_id !== (string) $actor->id || (string) $day->branch_id !== (string) $branchId) {
            throw new AccessDeniedHttpException('ONLY_WORKDAY_MANAGER');
        }
        CashierShift::withoutEagerLoads()->whereKey($shiftId)->lockForUpdate()->firstOrFail();
        if ($day->daily_report_submitted || $day->daily_report_submitted_at !== null
            || DB::table('cashier_shift_handovers')->where('cashier_shift_id', $shiftId)->whereNotNull('daily_closed_at')->lockForUpdate()->first() !== null
            || DB::table('asab_shifts')->where('legacy_shift_id', $shiftId)->whereIn('status', ['closed', 'pending_review'])->lockForUpdate()->first() !== null
            || DB::table('asab_operations')->where('module_key', 'shifts')->where('status', 'final-approved')
                ->whereIn('source_id', DB::table('asab_shifts')->where('legacy_shift_id', $shiftId)->select('id'))->lockForUpdate()->first() !== null) {
            throw new ConflictHttpException('REPORT_REOPEN_REQUIRED');
        }

        return ShiftLiabilityDailyLock::active()
            ->where('cashier_shift_id', $shiftId)
            ->where('branch_manager_shift_id', $workdayId)
            ->update([
                'superseded_at' => now(),
                'reopened_at' => now(),
                'reopened_by_type' => $actor->getMorphClass(),
                'reopened_by_id' => (string) $actor->getKey(),
                'reopen_reason' => $reason,
            ]);
    }

    private function readyScope(string $workdayId, BranchManager $manager): DailyCloseEvidence
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Daily liability guard requires the submit transaction.');
        }
        $workday = BranchManagerShift::withoutEagerLoads()->whereKey($workdayId)->lockForUpdate()->firstOrFail();
        if ($workday->branch_manager_id !== $manager->getKey()) {
            throw new AccessDeniedHttpException('ONLY_WORKDAY_MANAGER');
        }
        $scope = $this->evidence->dailyClose($workdayId);
        $identity = $this->actors->identity($manager, $scope->companyId, $scope->branchId);
        if ($scope->workdayId !== $workdayId || $scope->branchId !== $workday->branch_id || $identity['type'] !== 'branch_manager') {
            throw new ConflictHttpException('DAILY_SCOPE_MISMATCH');
        }
        $reports = $scope->reports;
        usort($reports, fn ($a, $b) => strcmp($a->shiftId, $b->shiftId));
        $seen = [];
        foreach ($reports as $report) {
            if ($report->companyId !== $scope->companyId || $report->branchId !== $scope->branchId || isset($seen[$report->shiftId])) {
                throw new ConflictHttpException('DAILY_REPORT_SCOPE_MISMATCH');
            }
            $seen[$report->shiftId] = true;
            // Re-resolve under the provider's locks; do not accept a stale scope snapshot.
            $current = $this->evidence->report($report->shiftId);
            if ($current != $report) {
                throw new ConflictHttpException('STALE_DAILY_REPORT');
            }
            $this->liabilities->assertDailyReportReady($current);
        }
        foreach ($scope->requiredReceipts as $transferId => $receiptId) {
            if (is_string($receiptId) && trim($receiptId) !== '') {
                continue;
            }

            $this->assertDocumentedPendingTransfer((string) $transferId);
        }

        return $scope;
    }

    /**
     * DAY-03 / RESOLVED-03: Allows daily submit while cash remains undelivered provided
     * exact pending amount, sender/holder, recipient, valid state, possession, and complete
     * shortage allocation are documented safely.
     */
    private function assertDocumentedPendingTransfer(string $transferKey): void
    {
        if (str_starts_with($transferKey, 'handover:')) {
            $handoverId = substr($transferKey, strlen('handover:'));
            $handover = CashierShiftHandover::withoutEagerLoads()->whereKey($handoverId)
                ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            if (! $handover) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            $leaf = $this->resolveHandoverLeaf($handover);

            $amountHalalas = \App\Support\ShiftFinancialCalculator::storedSarToHalalas($leaf->handover_amount ?? '0.00');
            if ($amountHalalas <= 0) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            $senderShift = CashierShift::withoutEagerLoads()->whereKey($leaf->cashier_shift_id)
                ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            if (! $senderShift || empty($senderShift->cashier_id)) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            if (empty($leaf->handover_to_type) || empty($leaf->handover_to_id)) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            if ($leaf->cancelled_at !== null) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            if ($leaf->current_transfer_attempt_id) {
                $attempt = \Modules\Shift\Models\ShiftTransferAttempt::whereKey($leaf->current_transfer_attempt_id)
                    ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
                if ($attempt) {
                    $attemptService = app(\Modules\Shift\Services\ShiftTransferAttemptService::class);
                    if ($attemptService->retained($attempt) > 0) {
                        throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
                    }
                    if (\Modules\Shift\Models\ShiftTransferReturn::where('transfer_attempt_id', $attempt->id)->whereNull('sender_confirmed_at')->exists()) {
                        throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
                    }
                }
            }

            if (\Modules\Shift\Models\CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $leaf->id)->exists()) {
                throw new ConflictHttpException('TRANSFER_RECEIPT_STATE_MISMATCH');
            }

            $senderReport = $this->evidence->report($senderShift->id);
            $this->liabilities->assertDailyReportReady($senderReport);

            return;
        }

        if (str_starts_with($transferKey, 'manager_transfer:')) {
            $transferId = substr($transferKey, strlen('manager_transfer:'));
            $transfer = \Modules\Shift\Models\BranchManagerCashTransfer::withoutEagerLoads()->whereKey($transferId)
                ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            if (! $transfer) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            $leaf = $this->resolveManagerTransferLeaf($transfer);

            $amountHalalas = \App\Support\ShiftFinancialCalculator::storedSarToHalalas($leaf->requested_amount ?? '0.00');
            if ($amountHalalas <= 0) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            $sourceWorkday = BranchManagerShift::withoutEagerLoads()->whereKey($leaf->branch_manager_shift_id)->first();
            if (! $sourceWorkday || empty($sourceWorkday->branch_manager_id)) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            $destShift = CashierShift::withoutEagerLoads()->whereKey($leaf->destination_cashier_shift_id)->first();
            if (! $destShift || empty($destShift->cashier_id)) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            if ($leaf->cancelled_at !== null) {
                throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
            }

            if ($leaf->current_transfer_attempt_id) {
                $attempt = \Modules\Shift\Models\ShiftTransferAttempt::whereKey($leaf->current_transfer_attempt_id)
                    ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
                if ($attempt) {
                    $attemptService = app(\Modules\Shift\Services\ShiftTransferAttemptService::class);
                    if ($attemptService->retained($attempt) > 0) {
                        throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
                    }
                    if (\Modules\Shift\Models\ShiftTransferReturn::where('transfer_attempt_id', $attempt->id)->whereNull('sender_confirmed_at')->exists()) {
                        throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
                    }
                }
            }

            if (\Modules\Shift\Models\CashierShiftHandoverReceipt::where('branch_manager_cash_transfer_id', $leaf->id)->exists()) {
                throw new ConflictHttpException('TRANSFER_RECEIPT_STATE_MISMATCH');
            }

            return;
        }

        throw new ConflictHttpException('PENDING_TRANSFER_EVIDENCE_INCOMPLETE');
    }

    private function resolveHandoverLeaf(CashierShiftHandover $handover): CashierShiftHandover
    {
        $visited = [];
        $current = $handover;
        while ($current->replacement_request_id !== null) {
            if (isset($visited[$current->id])) {
                throw new ConflictHttpException('REPLACEMENT_CHAIN_CYCLE_DETECTED');
            }
            $visited[$current->id] = true;
            $next = CashierShiftHandover::whereKey($current->replacement_request_id)
                ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            if ($next === null) {
                throw new ConflictHttpException('REPLACEMENT_CHAIN_LEAF_MISSING');
            }
            $current = $next;
        }

        return $current;
    }

    private function resolveManagerTransferLeaf(\Modules\Shift\Models\BranchManagerCashTransfer $transfer): \Modules\Shift\Models\BranchManagerCashTransfer
    {
        $visited = [];
        $current = $transfer;
        while ($current->replacement_request_id !== null) {
            if (isset($visited[$current->id])) {
                throw new ConflictHttpException('REPLACEMENT_CHAIN_CYCLE_DETECTED');
            }
            $visited[$current->id] = true;
            $next = \Modules\Shift\Models\BranchManagerCashTransfer::whereKey($current->replacement_request_id)
                ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            if ($next === null) {
                throw new ConflictHttpException('REPLACEMENT_CHAIN_LEAF_MISSING');
            }
            $current = $next;
        }

        return $current;
    }
}
