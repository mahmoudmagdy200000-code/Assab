<?php

namespace Modules\Shift\Liability;

/** Server-resolved S1-10/S1-11 evidence; never construct from an HTTP payload. */
final readonly class ReportEvidence
{
    public function __construct(
        public string $shiftId,
        public string $companyId,
        public string $branchId,
        public string $revision,
        public int $varianceHalalas,
        public bool $completed,
    ) {}
}
