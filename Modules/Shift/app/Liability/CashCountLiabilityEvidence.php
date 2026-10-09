<?php

namespace Modules\Shift\Liability;

use Illuminate\Support\Facades\DB;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftCashCountService;
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
            // Stable across handover-only revisions; changes only when the report is re-counted.
            (string) $count->counted_revision_id,
            $count->variance_halalas,
            $shift->status === ShiftStatus::COMPLETED,
        );
    }

    public function dailyClose(string $managerWorkdayId): DailyCloseEvidence
    {
        throw new ConflictHttpException('DAILY_SCOPE_EVIDENCE_UNAVAILABLE');
    }
}
