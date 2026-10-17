<?php

namespace Modules\Expense\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Modules\Custody\Exceptions\InsufficientExpenseCustodyException;

/**
 * EXP-05: the authoritative custody check runs inside the submission transaction and, when it refuses, the
 * client must get the same 400 + `custody_balance` contract the controller pre-check already returns — not the
 * generic 500 the surrounding `catch (\Exception)` would produce.
 */
trait RespondsToInsufficientCustody
{
    protected function insufficientCustodyResponse(InsufficientExpenseCustodyException $e): JsonResponse
    {
        return $this->errorResponse(
            InsufficientExpenseCustodyException::MESSAGE,
            400,
            ['custody_balance' => $e->availableSar, 'required_amount' => $e->requiredSar]
        );
    }
}
