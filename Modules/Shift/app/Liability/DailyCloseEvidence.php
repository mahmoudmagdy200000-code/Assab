<?php

namespace Modules\Shift\Liability;

/** Complete server-derived workday set, including any explicitly scoped carry-over. */
final readonly class DailyCloseEvidence
{
    /**
     * @param  list<ReportEvidence>  $reports
     * @param  array<string, string|null>  $requiredReceipts  Transfer ID => confirmed receipt evidence ID, or null.
     */
    public function __construct(
        public string $workdayId,
        public string $companyId,
        public string $branchId,
        public array $reports,
        public array $requiredReceipts,
    ) {}
}
