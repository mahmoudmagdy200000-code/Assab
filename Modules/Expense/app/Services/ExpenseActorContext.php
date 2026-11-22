<?php

namespace Modules\Expense\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cashier\Models\Cashier;


/** Resolves the branch custody owner while keeping the signed-in actor unchanged for audit. */
final class ExpenseActorContext
{
    public static function branchManagerId(): string
    {
        $actor = auth()->user();
        $delegatedManagerId = $actor instanceof Cashier
            ? DB::table('branch_manager_expense_delegations')
                ->where('cashier_id', $actor->id)
                ->where('branch_id', $actor->branch_id)
                ->whereNull('revoked_at')
                ->value('branch_manager_id')
            : null;

        return (string) (request()->attributes->get('expense_branch_manager_id') ?? $delegatedManagerId ?? auth()->id());
    }

    public static function actorType(): string
    {
        return auth()->user() instanceof Cashier ? 'cashier' : 'branch_manager';
    }
}
