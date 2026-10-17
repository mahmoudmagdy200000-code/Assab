<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Exceptions\InsufficientExpenseCustodyException;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Models\Expense;

/**
 * BRC v3.4 F-01 — the ONLY writer of an expense's custody movements (EXP-01…EXP-08, CASH-17).
 *
 * Ledger contract (AS-IS schema, no migration):
 *   - an expense paid from custody (`payment_method = 'custody'`) is charged to the BRANCH expense
 *     custody (`custody_transactions`) only — never to the manager's sales-cash / personal ledger;
 *   - the charge is one cash-out row of type `Expenses Deduction`, posted when the expense is
 *     SUBMITTED for review (EXP-02); approval confirms it and posts nothing;
 *   - a rejection posts one reversing cash-in row of the same type, manager and branch as the row it
 *     reverses (EXP-03) — `custody_transactions.type` is an ENUM, so no new type is introduced. The
 *     original and the reversal are both kept as audit evidence (EXP-04);
 *   - the net outstanding charge of an expense is Σ(out) − Σ(in) of its rows. Every operation is
 *     derived from it, which makes each one idempotent: a retry finds the work already done and
 *     posts nothing (no duplicate financial effect), and a corrected resubmission (EXP-08) charges
 *     the corrected amount once because the first charge was reversed at rejection.
 *
 * Amounts: `custody_transactions.amount` and `expenses.total_amount` are decimal SAR (2 dp). They are
 * compared in integer halalas, so no floating-point rounding can decide a sufficiency check.
 *
 * Every public method must run inside (or opens) a database transaction together with the status
 * change / audit row it belongs to; the manager row is locked so concurrent submissions of the same
 * custody serialize on MySQL (SQLite has no row locks and a single writer).
 */
class ExpenseCustodyService
{
    public const TYPE = 'Expenses Deduction';

    /** Expenses paid from branch custody are the only ones that move it (EXP-01). */
    public function isCustodyPaid(Expense $expense): bool
    {
        return $expense->payment_method === 'custody';
    }

    /**
     * Charge the expense to the branch custody once, when it is submitted / resubmitted (EXP-02, EXP-08),
     * or — for an expense submitted before the charge moved to submission — when it is approved.
     *
     * @throws InsufficientExpenseCustodyException when the custody does not cover it (EXP-05); nothing is written
     */
    public function reserve(Expense $expense): void
    {
        if (! $this->isCustodyPaid($expense)) {
            return;
        }

        DB::transaction(function () use ($expense) {
            $manager = $this->lockManager($expense);
            if ($manager === null || $manager->branch_id === null) {
                throw new InsufficientExpenseCustodyException(0.0, (float) $expense->total_amount);
            }

            // Already charged (client retry, repeated submit, approval after submission): no second effect.
            if ($this->outstandingHalalas($expense->id) > 0) {
                return;
            }

            $branchId = $expense->origin_branch_id ?: $manager->branch_id;
            $required = $this->halalas($expense->total_amount);
            $available = $this->balanceHalalas($manager->id, $branchId);

            if ($required <= 0) {
                return;
            }
            if ($available < $required) {
                throw new InsufficientExpenseCustodyException($available / 100, $required / 100);
            }

            CustodyTransaction::create([
                'branch_manager_id' => $manager->id,
                'branch_id' => $branchId,
                'type' => self::TYPE,
                'amount' => number_format($required / 100, 2, '.', ''),
                'is_cash_in' => false,
                'related_expense_id' => $expense->id,
                'transaction_date' => now(),
            ]);
        });
    }

    /**
     * Reverse the expense's outstanding charge exactly once when it is rejected (EXP-03). The reversal goes back
     * to the same manager and branch custody the charge was taken from. An expense that was never charged
     * (not custody-paid, or submitted before this change) has nothing to reverse.
     */
    public function reverse(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $this->lockManager($expense);

            $outstanding = $this->outstandingHalalas($expense->id);
            if ($outstanding <= 0) {
                return;
            }

            $charge = CustodyTransaction::where('related_expense_id', $expense->id)
                ->where('type', self::TYPE)->where('is_cash_in', false)
                ->orderByDesc('created_at')->orderByDesc('id')->first();

            CustodyTransaction::create([
                'branch_manager_id' => $charge->branch_manager_id,
                'branch_id' => $charge->branch_id,
                'type' => self::TYPE,
                'amount' => number_format($outstanding / 100, 2, '.', ''),
                'is_cash_in' => true,
                'related_expense_id' => $expense->id,
                'transaction_date' => now(),
            ]);
        });
    }

    /** Net amount currently charged to custody for the expense (cash-out minus cash-in), in halalas. */
    public function outstandingHalalas(string $expenseId): int
    {
        $net = CustodyTransaction::where('related_expense_id', $expenseId)
            ->where('type', self::TYPE)
            ->selectRaw('SUM(CASE WHEN is_cash_in = 1 THEN -amount ELSE amount END) as net')
            ->value('net');

        return $this->halalas($net ?? 0);
    }

    /** Branch expense custody of the manager at the branch, in halalas — sales cash is never part of it. */
    private function balanceHalalas(string $managerId, string $branchId): int
    {
        return $this->halalas(app(CustodyBalanceService::class)->getCustodyBalance($managerId, $branchId));
    }

    private function lockManager(Expense $expense): ?BranchManager
    {
        return $expense->branch_manager_id
            ? BranchManager::whereKey($expense->branch_manager_id)->lockForUpdate()->first()
            : null;
    }

    private function halalas(string|int|float $sar): int
    {
        return (int) round(((float) $sar) * 100);
    }
}
