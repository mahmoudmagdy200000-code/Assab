<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Operation;
use Modules\Expense\Models\Expense;

/**
 * Reverse leg of the expense bridge (WS1b): when the dashboard reaches a
 * terminal decision on an expense operation, write the outcome back onto the
 * legacy `expenses` row so the mobile app leaves «معلق». The forward bridge
 * (ExpenseBridgeService) only mirrored the mobile expense INTO asab_operations;
 * the accountant/head decision never returned.
 *
 * Direct-DB write (mirrors the read bridge; the Expense model fires no Eloquent
 * events, so this cannot re-enter SyncLegacyExpenseOperation). Routing through
 * ExpenseApprovalService is deliberately avoided — it dispatches domain events
 * that re-enter the read bridge and its guards reject non-pending source states.
 */
class ExpenseFeedbackBridgeService
{
    public function syncFromOperation(Operation $op): void
    {
        if ($op->source_module !== ExpenseBridgeService::SOURCE || $op->source_id === null) {
            return;
        }

        $target = match ($op->status) {
            Operation::STATUS_FINAL => 'approved',
            Operation::STATUS_REJECTED => 'rejected',
            default => null, // intermediate accountant-approved / توثيق has no mobile target
        };
        if ($target === null) {
            return;
        }

        // Update strictly by primary key — the legacy table has no tenant column,
        // so an over-broad query could touch another company's expense.
        $expense = Expense::find($op->source_id);
        if ($expense === null || $expense->status === $target) {
            return; // gone/soft-deleted, or already synced (idempotent).
        }

        DB::transaction(function () use ($expense, $op, $target) {
            $data = ['status' => $target];
            if ($target === 'approved') {
                $data['approved_at'] = now();
            } else {
                $data['rejected_at'] = now();
                $data['rejection_reason'] = $op->reject_reason;
            }
            // Leave approved_by / rejected_by NULL: they are FKs to the legacy
            // `users` table and an AsabUser id would violate the constraint. The
            // dashboard actor is captured on the asab_operations side.
            $expense->update($data);
        });
    }
}
