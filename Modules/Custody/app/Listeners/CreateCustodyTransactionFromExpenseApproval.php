<?php

namespace Modules\Custody\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Custody\Services\CustodyBalanceService;
use Modules\Expense\Events\ExpenseApprovedEvent;

/**
 * When an expense with payment_method = 'custody' is approved, deduct it from
 * the manager's custody. Spendable custody = branch custody + personal ledger
 * (sales cash in hand), so the deduction takes the branch pot first and any
 * remainder comes off the personal ledger.
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
        if (! $branchManager || ! $branchManager->branch_id) {
            return;
        }

        // Queued with retries — a second delivery must not deduct twice.
        $alreadyDeducted = CustodyTransaction::where('related_expense_id', $expense->id)
            ->where('type', 'Expenses Deduction')->exists()
            || PersonalLedgerTransaction::where('related_expense_id', $expense->id)
                ->where('transaction_type', 'Expenses Deduction')->exists();
        if ($alreadyDeducted) {
            return;
        }

        $amount = (float) $expense->total_amount;
        $branchBalance = app(CustodyBalanceService::class)->getCustodyBalance($expense->branch_manager_id);
        $fromBranch = round(min($amount, max($branchBalance, 0)), 2);
        $fromPersonal = round($amount - $fromBranch, 2);

        if ($fromBranch > 0) {
            CustodyTransaction::create([
                'branch_manager_id' => $expense->branch_manager_id,
                'branch_id' => $branchManager->branch_id,
                'type' => 'Expenses Deduction',
                'amount' => $fromBranch,
                'is_cash_in' => false,
                'related_expense_id' => $expense->id,
                'transaction_date' => now(),
            ]);
        }

        if ($fromPersonal > 0) {
            PersonalLedgerTransaction::create([
                'branch_manager_id' => $expense->branch_manager_id,
                'transaction_type' => 'Expenses Deduction',
                'amount' => $fromPersonal,
                'is_cash_in' => false,
                'related_expense_id' => $expense->id,
                'transaction_date' => now(),
            ]);
        }
    }
}
