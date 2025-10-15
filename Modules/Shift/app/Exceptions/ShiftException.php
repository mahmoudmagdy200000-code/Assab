<?php

namespace Modules\Shift\Exceptions;

use Exception;

class ShiftException extends Exception
{
    public static function shiftNotFound(int $id): self
    {
        return new self("Shift with ID {$id} not found", 404);
    }

    public static function shiftAlreadyStarted(): self
    {
        return new self("Shift has already been started", 400);
    }

    public static function shiftNotInProgress(): self
    {
        return new self("Shift is not in progress", 400);
    }

    public static function cashierNotAvailable(): self
    {
        return new self("Cashier is not available for this shift", 400);
    }

    public static function invalidPaymentBreakdown(): self
    {
        return new self("Payment breakdown does not match total sales", 422);
    }

    public static function handoverAlreadyProcessed(): self
    {
        return new self("Handover has already been processed", 400);
    }

    public static function varianceNotRecorded(): self
    {
        return new self("Variance must be recorded when handover amount differs from expected", 422);
    }

    public static function unauthorizedBranch(): self
    {
        return new self("You are not authorized to manage this shift", 403);
    }
}
