<?php

namespace Modules\Custody\Exceptions;

use RuntimeException;

/**
 * EXP-05 — the branch expense custody does not cover a custody-paid expense, so it cannot be
 * submitted (or, for an expense that was submitted before the deduction moved to submission,
 * approved) until a top-up is recorded. The message is the stable text the clients already
 * receive from the controller pre-check.
 */
class InsufficientExpenseCustodyException extends RuntimeException
{
    public const MESSAGE = 'Insufficient custody balance';

    public function __construct(
        public readonly float $availableSar,
        public readonly float $requiredSar,
    ) {
        parent::__construct(self::MESSAGE);
    }
}
