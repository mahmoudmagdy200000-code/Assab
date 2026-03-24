<?php

namespace Modules\Custody\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\BranchManagers\Models\BranchManager;

/**
 * When an expense with payment_method = 'custody' is approved,
 * create a CustodyTransaction (Expenses Deduction) to link Expense and Custody.
 */
class CreateCustodyTransactionFromExpenseApproval implements ShouldQueue
{
    use InteractsWithQueue;

    public $afterCommit = true;
    public $tries = 3;

    public function backoff(): array
    {
        $jitter = random_int(1, 4);
        return [10 + $jitter, 30 + $jitter, 90 + $jitter];
    }

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
