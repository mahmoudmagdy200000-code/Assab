<?php

namespace Modules\Shift\Liability;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Fail closed until S1-10/S1-11 supply real evidence. No legacy variance fallback. */
final class UnavailableLiabilityEvidence implements LiabilityEvidenceSource
{
    public function report(string $cashierShiftId): ReportEvidence
    {
        throw new ConflictHttpException('LIABILITY_EVIDENCE_UNAVAILABLE');
    }

    public function dailyClose(string $managerWorkdayId): DailyCloseEvidence
    {
        throw new ConflictHttpException('DAILY_SCOPE_EVIDENCE_UNAVAILABLE');
    }
}
