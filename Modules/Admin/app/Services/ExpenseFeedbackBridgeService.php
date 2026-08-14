<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Expense\Enums\ExpenseApprovalStage;
use Modules\Expense\Models\Expense;

/**
 * Reverse leg of the expense bridge (WS1b): every dashboard decision on an
 * expense operation is written back onto the legacy `expenses` row, so the
 * mobile app shows the branch manager and the brand owner where the record
 * actually stands. The forward bridge (ExpenseBridgeService) only mirrors the
 * mobile expense INTO asab_operations.
 *
 * The chain the meeting of 2026-08-14 fixed (accountant cycle):
 *
 *   accountant approves → mobile stays «pending», stage «موافق عليه من المحاسب»
 *                         (it is NOT approved yet — the head still has to act)
 *   head final-approves → mobile «approved»,  stage «معتمد نهائياً من رئيس الحسابات»
 *   head returns it     → mobile «pending»,   stage «أعادها رئيس الحسابات»
 *   accountant rejects  → mobile «rejected»,  stage «مرفوض من المحاسب» — back in
 *                         the branch manager's Approval tab, resubmittable.
 *
 * Only the accountant cycle is written here. A brand-owner decision travels the
 * other way (mobile → operation) and is never overwritten.
 *
 * Direct-DB write (mirrors the read bridge; the Expense model fires no Eloquent
 * events, so this cannot re-enter SyncLegacyExpenseOperation). Routing through
 * ExpenseApprovalService is deliberately avoided — it dispatches domain events
 * that re-enter the read bridge and its guards reject non-pending source states.
 */
class ExpenseFeedbackBridgeService
{
    /**
     * @param  AsabUser|null  $actor  the acting dashboard user, when the transition
     *                                stores no actor id of its own («إرجاع للمراجعة»
     *                                clears the approval stamps by design)
     */
    public function syncFromOperation(Operation $op, ?AsabUser $actor = null): void
    {
        if ($op->source_module !== ExpenseBridgeService::SOURCE || $op->source_id === null) {
            return;
        }

        $decision = $this->decisionFor($op);
        if ($decision === null) {
            return;
        }

        // Update strictly by primary key — the legacy table has no tenant column,
        // so an over-broad query could touch another company's expense.
        $expense = Expense::find($op->source_id);
        if ($expense === null) {
            return; // gone / soft-deleted
        }

        // The brand owner decided first: their outcome is terminal and the
        // dashboard is a spectator. Never let a stale operation event undo it.
        if ($expense->approval_stage !== null && ! $expense->approval_stage->isAccountingCycle()) {
            return;
        }

        [$status, $stage, $actorId] = $decision;

        // «pending» only MEANS «returned for review» for a record the accountant
        // had already approved. On anything else it is the ordinary fresh state
        // and there is nothing to write back.
        if ($stage === ExpenseApprovalStage::RETURNED_TO_ACCOUNTANT
            && $expense->approval_stage !== ExpenseApprovalStage::ACCOUNTANT_APPROVED
        ) {
            return;
        }

        if ($expense->status === $status && $expense->approval_stage === $stage) {
            return; // already synced (idempotent)
        }

        $actor = $actorId === null ? $actor : AsabUser::withoutGlobalScopes()->find($actorId);

        DB::transaction(function () use ($expense, $op, $status, $stage, $actor) {
            $data = [
                'status' => $status,
                'approval_stage' => $stage,
                'decided_by_name' => $actor?->name,
                'decided_by_role' => $stage->actorRole(),
                'decided_at' => now(),
            ];

            if ($stage === ExpenseApprovalStage::HEAD_APPROVED) {
                $data['approved_at'] = now();
            } elseif ($status === 'rejected') {
                $data['rejected_at'] = now();
                $data['rejection_reason'] = $op->reject_reason;
            } else {
                // Back in review: clear the stamps of the decision being undone
                // so the mobile screens do not print a rejection alongside a
                // pending badge.
                $data['approved_at'] = null;
                $data['rejected_at'] = null;
                $data['rejection_reason'] = null;
            }

            // Leave approved_by / rejected_by NULL: they are FKs to the legacy
            // `users` table and an AsabUser id would violate the constraint. The
            // actor's NAME rides in decided_by_name, which is what the screens
            // print («وتتكتب اسم رئيس الحسابات»).
            $expense->update($data);
        });
    }

    /**
     * The legacy (status, stage, actorId) triple an operation state maps to,
     * or null for a state with no mobile meaning.
     *
     * @return array{0: string, 1: ExpenseApprovalStage, 2: ?string}|null
     */
    private function decisionFor(Operation $op): ?array
    {
        return match ($op->status) {
            // Intermediate: reviewed by the accountant, NOT yet approved. The
            // mobile record deliberately stays «pending» — only the head's
            // final approval turns it green.
            Operation::STATUS_APPROVED => ['pending', ExpenseApprovalStage::ACCOUNTANT_APPROVED, $op->approved_by_id],
            Operation::STATUS_FINAL => ['approved', ExpenseApprovalStage::HEAD_APPROVED, $op->final_approved_by_id],
            Operation::STATUS_REJECTED => ['rejected', $this->rejectionStage($op), $op->rejected_by_id],
            // Head returned an approved op to the accountant's queue.
            Operation::STATUS_PENDING => ['pending', ExpenseApprovalStage::RETURNED_TO_ACCOUNTANT, null],
            default => null,
        };
    }

    /**
     * Who rejected. The head's rejection of an APPROVED operation is a return
     * to the accountant, not a rejection (OperationService), so a terminal
     * rejection is the accountant's in every normal flow — but a head rejecting
     * a still-pending record is labelled honestly.
     */
    private function rejectionStage(Operation $op): ExpenseApprovalStage
    {
        $actor = $op->rejected_by_id === null
            ? null
            : AsabUser::withoutGlobalScopes()->find($op->rejected_by_id);

        return $actor?->hasAnyAsabRole(['head'])
            ? ExpenseApprovalStage::HEAD_REJECTED
            : ExpenseApprovalStage::ACCOUNTANT_REJECTED;
    }
}
