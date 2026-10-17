<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cashier\Models\Cashier;
use Symfony\Component\HttpFoundation\Response;

/**
 * BRC v3.4 EXP-09 — a cashier may create/submit/alter an expense ONLY when the Branch Manager has
 * delegated that permission to him; a cashier without it must not get the operation automatically.
 *
 * Applied to the expense WRITE routes that live behind `branch.manager.or.cashier`. The repository has
 * no persisted delegation (no permission column/table, no grant endpoint — that needs a schema
 * migration which is outside this package), so no cashier currently holds the permission and every
 * cashier is refused here, before validation, any write, event or custody movement.
 *
 * When the delegation store exists, this middleware is the single place that consults it.
 * A Branch Manager is never affected.
 */
class RequireExpenseWriteDelegationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->user() instanceof Cashier) {
            return response()->json([
                'success' => false,
                'message' => 'Expense creation and submission have not been delegated to cashiers. Ask your Branch Manager.',
                'errors' => ['code' => 'EXPENSE_DELEGATION_REQUIRED'],
            ], 403);
        }

        return $next($request);
    }
}
