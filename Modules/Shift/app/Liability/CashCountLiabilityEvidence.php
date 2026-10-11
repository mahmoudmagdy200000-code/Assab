<?php

namespace Modules\Shift\Liability;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftDailyCloseScopeService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * S1-10 report evidence from the stored physical count of the CURRENT report revision.
 *
 * A report without a stored count (historical, auto-closed, or count unavailable) is not evidence:
 * it fails closed with LIABILITY_EVIDENCE_UNAVAILABLE. Legacy cash_collected/closing_balance/variance
 * are never a fallback. Daily-close membership remains S1-11.
 */
final class CashCountLiabilityEvidence implements LiabilityEvidenceSource
{
    public function __construct(
        private ShiftCashCountService $counts,
        private ShiftReportRevisionService $revisions,
    ) {}

    public function report(string $cashierShiftId): ReportEvidence
    {
        $shift = CashierShift::without(['cashier', 'shift', 'nextCashier'])->whereKey($cashierShiftId)->first();
        $revision = $shift ? $this->revisions->currentCashierRevision($shift) : null;
        $count = $revision ? $this->counts->currentFor($cashierShiftId) : null;
        if (! $shift || ! $revision || ! $count) {
            throw new ConflictHttpException('LIABILITY_EVIDENCE_UNAVAILABLE');
        }

        $branch = DB::table('shifts')->join('branches', 'branches.id', '=', 'shifts.branch_id')
            ->where('shifts.id', $shift->shift_id)->select('branches.id', 'branches.asab_company_id')->first();
        if (! $branch || ! $branch->asab_company_id) {
            throw new ConflictHttpException('LIABILITY_COMPANY_MAPPING_REQUIRED');
        }

        return new ReportEvidence(
            $cashierShiftId,
            (string) $branch->asab_company_id,
            (string) $branch->id,
            // Stable across handover-only revisions; changes when the report numbers are corrected or re-counted.
            (string) ($count->evidence_revision_id ?? $count->counted_revision_id),
            $count->variance_halalas,
            // A submitted, counted report: completed, or reassigned-with-handover after its report.
            in_array($shift->status, [ShiftStatus::COMPLETED, ShiftStatus::REASSIGNED], true),
        );
    }

    public function dailyClose(string $managerWorkdayId): DailyCloseEvidence
    {
        $day = BranchManagerShift::findOrFail($managerWorkdayId);
        $branch = DB::table('branches')->where('id', $day->branch_id)->first();
        if (! $branch || ! $branch->asab_company_id) {
            throw new ConflictHttpException('LIABILITY_COMPANY_MAPPING_REQUIRED');
        }

        $scopeService = app(ShiftDailyCloseScopeService::class);
        $scope = $scopeService->resolve($day, DB::transactionLevel() > 0);

        $reports = [];
        foreach ($scope['cashier_shift_ids'] as $shiftId) {
            $shift = CashierShift::without(['cashier', 'shift', 'nextCashier'])->find($shiftId);
            if (! $shift) {
                throw new ConflictHttpException('LIABILITY_EVIDENCE_UNAVAILABLE');
            }
            $revision = $this->revisions->currentCashierRevision($shift);
            if (! $revision) {
                throw new ConflictHttpException('LIABILITY_EVIDENCE_UNAVAILABLE');
            }
            $reports[] = $this->report($shiftId);
        }

        $requiredReceipts = [];
        foreach ($scope['handover_ids'] as $handoverId) {
            $handover = CashierShiftHandover::find($handoverId);
            if (! $handover) {
                throw new ConflictHttpException('LIABILITY_EVIDENCE_UNAVAILABLE');
            }
            $requiredReceipts["handover:{$handoverId}"] = $scopeService->resolveHandoverReceiptId($handover);
        }

        foreach ($scope['manager_transfer_ids'] as $transferId) {
            $transfer = BranchManagerCashTransfer::find($transferId);
            if (! $transfer) {
                throw new ConflictHttpException('LIABILITY_EVIDENCE_UNAVAILABLE');
            }
            $requiredReceipts["manager_transfer:{$transferId}"] = $scopeService->resolveManagerTransferReceiptId($transfer);
        }

        return new DailyCloseEvidence(
            workdayId: $managerWorkdayId,
            companyId: (string) $branch->asab_company_id,
            branchId: (string) $branch->id,
            reports: $reports,
            requiredReceipts: $requiredReceipts,
        );
    }
}
