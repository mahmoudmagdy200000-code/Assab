<?php

namespace Modules\Shift\Liability;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\BranchManagerShift;
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
        $workday = BranchManagerShift::withoutEagerLoads()->whereKey($workdayId)->lockForUpdate()->firstOrFail();
        if ($workday->branch_manager_id !== $manager->getKey()) {
            throw new AccessDeniedHttpException('ONLY_WORKDAY_MANAGER');
        }

        return ShiftLiabilityDailyLock::active()->where('branch_manager_shift_id', $workdayId)->update([
            'released_by_id' => (string) $manager->getKey(),
            'released_at' => now(),
            'release_reason' => $reason,
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
            if (! is_string($transferId) || $transferId === '' || ! is_string($receiptId) || trim($receiptId) === '') {
                throw new ConflictHttpException('REQUIRED_TRANSFER_RECEIPT_PENDING');
            }
        }

        return $scope;
    }
}
