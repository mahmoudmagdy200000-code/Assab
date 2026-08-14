<?php

namespace Modules\Expense\Services;

use Modules\Expense\Enums\ExpenseApprovalStage;
use Modules\Expense\Models\Expense;

/**
 * Expense Approval Service
 * Handles approval/rejection by Brand Owner.
 *
 * The brand owner is ONE of the two approval cycles (meeting 2026-08-14): the
 * dashboard accountant may take the same pending expense, and whoever moves
 * first owns it. `assertBrandOwnerMayDecide` is the guard for that race — the
 * dashboard side is guarded by the operation's own state machine.
 */
class ExpenseApprovalService
{
    /**
     * Submit expense for approval
     */
    public function submitExpense(Expense $expense): void
    {
        // Already pending (created non-draft): re-submitting is a no-op on the
        // record, but re-fire the event so the ASAB bridge (idempotent) can
        // catch anything that slipped — never 500 the client for it.
        if ($expense->status === 'pending') {
            event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense));

            return;
        }

        if ($expense->status !== 'draft') {
            throw new \Exception('Only draft expenses can be submitted');
        }

        $expense->update([
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        // Create timeline entry
        $this->createTimelineEntry($expense, 'submit', 'submitted');

        // Fire event for notifications
        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense));
    }

    /**
     * Approve expense (Brand Owner).
     *
     * Terminal for everyone: the accountant and the head of accounts see the
     * record as «موافق عليه من <العلامة التجارية>» and can take no action on it.
     */
    public function approveExpense(Expense $expense, string $brandOwnerId, ?string $brandOwnerName = null): void
    {
        $this->assertBrandOwnerMayDecide($expense, 'approved');

        $expense->update([
            'status' => 'approved',
            'approved_by' => $brandOwnerId,
            'approved_at' => now(),
            'approval_stage' => ExpenseApprovalStage::BRAND_OWNER_APPROVED,
            'decided_by_name' => $brandOwnerName,
            'decided_by_role' => 'brand_owner',
            'decided_at' => now(),
        ]);

        // Create timeline entry
        $this->createTimelineEntry($expense, 'approve', 'approved', $brandOwnerId, 'brand_owner');

        // Fire event for notifications + the ASAB bridge, which closes the
        // mirrored operation so the dashboard cannot decide it a second time.
        event(new \Modules\Expense\Events\ExpenseApprovedEvent($expense->fresh(), $brandOwnerId));
    }

    /**
     * Reject expense (Brand Owner). Terminal, like the approval above.
     */
    public function rejectExpense(Expense $expense, string $brandOwnerId, string $reason, ?string $brandOwnerName = null): void
    {
        $this->assertBrandOwnerMayDecide($expense, 'rejected');

        $expense->update([
            'status' => 'rejected',
            'rejected_by' => $brandOwnerId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'approval_stage' => ExpenseApprovalStage::BRAND_OWNER_REJECTED,
            'decided_by_name' => $brandOwnerName,
            'decided_by_role' => 'brand_owner',
            'decided_at' => now(),
        ]);

        // Create timeline entry
        $this->createTimelineEntry($expense, 'reject', 'rejected', $brandOwnerId, 'brand_owner', $reason);

        // Fire event for notifications
        event(new \Modules\Expense\Events\ExpenseRejectedEvent($expense->fresh(), $brandOwnerId, $reason));
    }

    /**
     * The brand owner may only decide an expense nobody else has decided.
     * A record already inside the accountant cycle (approved by the accountant,
     * or waiting on the head) is read-only for them.
     */
    private function assertBrandOwnerMayDecide(Expense $expense, string $action): void
    {
        if ($expense->isOwnedByAccounting()) {
            throw new \Exception(
                'This expense is already in the accounting review cycle ('
                .$expense->approval_stage->label().') and cannot be '.$action.' by the brand owner'
            );
        }

        if ($expense->status !== 'pending') {
            throw new \Exception('Only pending expenses can be '.$action);
        }
    }

    /**
     * Re-submit rejected expense — a fresh cycle, so the previous decision is
     * cleared. Without this the record kept its «مرفوض من المحاسب» stage and
     * the dashboard treated the resubmission as still-rejected.
     */
    public function resubmitExpense(Expense $expense): void
    {
        if ($expense->status !== 'rejected') {
            throw new \Exception('Only rejected expenses can be resubmitted');
        }

        $expense->update([
            'status' => 'pending',
            'submitted_at' => now(),
            'rejection_reason' => null,
            'approval_stage' => null,
            'decided_by_name' => null,
            'decided_by_role' => null,
            'decided_at' => null,
            'rejected_at' => null,
            'rejected_by' => null,
        ]);

        // Create timeline entry
        $this->createTimelineEntry($expense, 'resubmit', 'resubmitted');

        // Fire event for notifications (same as submitted)
        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense));
    }

    /**
     * View expense (Brand Owner)
     */
    public function markAsViewed(Expense $expense, string $brandOwnerId): void
    {
        // Create timeline entry
        $this->createTimelineEntry($expense, 'view', 'viewed', $brandOwnerId, 'brand_owner');
    }

    /**
     * Edit expense (Brand Owner)
     */
    public function recordEdit(Expense $expense, string $brandOwnerId, array $changes): void
    {
        // Create timeline entry with changes
        $this->createTimelineEntry(
            $expense,
            'edit',
            'edited',
            $brandOwnerId,
            'brand_owner',
            json_encode($changes)
        );
    }

    private function createTimelineEntry(
        Expense $expense,
        string $action,
        string $status,
        ?string $performedBy = null,
        ?string $performedByType = null,
        ?string $notes = null
    ): void {
        $expense->timelines()->create([
            'action' => $action,
            'performed_by' => $performedBy ?? auth()->id(),
            'performed_by_type' => $performedByType ?? 'branch_manager',
            'status' => $status,
            'notes' => $notes,
        ]);
    }
}
