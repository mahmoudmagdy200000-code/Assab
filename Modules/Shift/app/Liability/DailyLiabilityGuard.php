<?php

namespace Modules\Shift\Liability;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\BranchManagerShift;
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
    }
}
