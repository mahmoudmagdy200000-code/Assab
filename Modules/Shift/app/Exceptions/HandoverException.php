<?php

namespace Modules\Shift\Exceptions;

use RuntimeException;

class HandoverException extends RuntimeException
{
    public static function cannotBeRejected(string $currentStatus): self
    {
        return new self("This handover cannot be rejected. Current status: {$currentStatus}");
    }

    public static function cannotBeEdited(): self
    {
        return new self('Handover cannot be edited. Either not rejected or permanently rejected.');
    }

    public static function notAuthorizedToAccept(): self
    {
        return new self('You are not authorized to accept this handover.');
    }

    public static function notAuthorizedToReject(): self
    {
        return new self('You are not authorized to reject this handover.');
    }

    public static function notInReassignedStatus(): self
    {
        return new self('Shift is not in reassigned status.');
    }

    public static function notAuthorizedForReassignment(): self
    {
        return new self('You are not authorized to accept this reassigned shift.');
    }

    public static function noOriginalCashier(): self
    {
        return new self('Cannot revert: original cashier is unknown.');
    }

    public static function rejectionOnlyForHandoverReassignment(): self
    {
        return new self('Rejection is only available for shifts reassigned with handover.');
    }

    public static function reassignedShiftNotPending(): self
    {
        return new self('This reassigned shift is no longer pending acceptance.');
    }
}
