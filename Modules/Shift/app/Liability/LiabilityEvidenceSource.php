<?php

namespace Modules\Shift\Liability;

interface LiabilityEvidenceSource
{
    /** Resolve and lock current counted-cash/confirmed-opening revision in the caller's transaction. */
    public function report(string $cashierShiftId): ReportEvidence;

    /** Lock complete report/transfer membership; approved handover status is NOT receipt evidence. */
    public function dailyClose(string $managerWorkdayId): DailyCloseEvidence;
}
