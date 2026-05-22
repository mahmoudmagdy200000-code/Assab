<?php

namespace Modules\BranchManagers\Exceptions;

use RuntimeException;

/**
 * Domain exception for branch manager settings operations.
 *
 * Carries the HTTP status the global/controller layer should translate
 * into the unified JSON error envelope.
 */
class BranchManagerSettingsException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function aggregatorAlreadyAdded(): self
    {
        return new self('This aggregator is already added to the branch.', 409);
    }

    public static function aggregatorNotAdded(): self
    {
        return new self('This aggregator is not added to the branch.', 404);
    }

    public static function incorrectCurrentPassword(): self
    {
        return new self('The current password is incorrect.', 422);
    }
}
