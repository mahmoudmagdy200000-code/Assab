<?php

namespace Modules\Custody\Listeners;

use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\BranchManagers\Models\BranchManager;

/**
 * When an expense with payment_method = 'custody' is approved,
 * create a CustodyTransaction (Expenses Deduction) to link Expense and Custody.
 */
class CreateCustodyTransactionFromExpenseApproval
{
    /**
     * Handle the event.
     */
    public function handle(ExpenseApprovedEvent $event): void
    {
        $expense = $event->expense;

        if (($expense->payment_method ?? '') !== 'custody') {
            return;
        }

        $branchManager = BranchManager::find($expense->branch_manager_id);
        if (!$branchManager || !$branchManager->branch_id) {
            return;
        }

        CustodyTransaction::create([
            'branch_manager_id' => $expense->branch_manager_id,
            'branch_id' => $branchManager->branch_id,
            'type' => 'Expenses Deduction',
            'amount' => $expense->total_amount,
            'is_cash_in' => false,
            'related_expense_id' => $expense->id,
            'transaction_date' => now(),
        ]);
    }
}
