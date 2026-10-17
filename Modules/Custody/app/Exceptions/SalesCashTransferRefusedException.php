<?php

namespace Modules\Custody\Exceptions;

use RuntimeException;

/** FUND-01 / FUND-02: the sales-cash -> custody transfer is refused and nothing was written. */
class SalesCashTransferRefusedException extends RuntimeException
{
    public const SOURCE_DAY_NOT_FULLY_APPROVED = 'SOURCE_DAY_NOT_FULLY_APPROVED';

    public const EXCEEDS_AVAILABLE_CASH = 'EXCEEDS_AVAILABLE_CASH';

    public function __construct(
        public readonly string $reason,
        public readonly int $availableHalalas,
        public readonly int $unapprovedHalalas,
        public readonly int $transferableHalalas,
    ) {
        parent::__construct($reason === self::SOURCE_DAY_NOT_FULLY_APPROVED
            ? 'Transfer amount exceeds the cash released by fully approved days'
            : 'Transfer amount cannot exceed personal custody balance');
    }
}
