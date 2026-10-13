<?php

namespace Modules\Expense\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An expense cannot enter the financially-accountable cycle (pending / approved)
 * because its immutable origin (branch + brand + company) is not completely and
 * consistently resolvable. Fail-closed: we never guess ownership from a manager's
 * current branch, and we never silently downgrade an invalid submission to a draft.
 *
 * Renders as a 422 in the mobile {success,message} envelope, so it is a clear 4xx
 * on paths that let it propagate; controllers that wrap the service in a catch-all
 * also map it explicitly to 422 (before any 500 fallback).
 */
class UnresolvedExpenseOriginException extends \Exception
{
    public int $status = 422;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
