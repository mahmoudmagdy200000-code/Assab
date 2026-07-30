<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\Expense;

/**
 * Expense Approval Service
 * Handles approval/rejection by Brand Owner
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
     * Approve expense (Brand Owner)
     */
    public function approveExpense(Expense $expense, string $brandOwnerId): void
    {
        if ($expense->status !== 'pending') {
            throw new \Exception('Only pending expenses can be approved');
        }

        $expense->update([
            'status' => 'approved',
            'approved_by' => $brandOwnerId,
            'approved_at' => now(),
        ]);

        // Create timeline entry
        $this->createTimelineEntry($expense, 'approve', 'approved', $brandOwnerId, 'brand_owner');

        // Fire event for notifications
        event(new \Modules\Expense\Events\ExpenseApprovedEvent($expense, $brandOwnerId));
    }

    /**
     * Reject expense (Brand Owner)
     */
    public function rejectExpense(Expense $expense, string $brandOwnerId, string $reason): void
    {
        if ($expense->status !== 'pending') {
            throw new \Exception('Only pending expenses can be rejected');
        }

        $expense->update([
            'status' => 'rejected',
            'rejected_by' => $brandOwnerId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Create timeline entry
        $this->createTimelineEntry($expense, 'reject', 'rejected', $brandOwnerId, 'brand_owner', $reason);

        // Fire event for notifications
        event(new \Modules\Expense\Events\ExpenseRejectedEvent($expense, $brandOwnerId, $reason));
    }

    /**
     * Re-submit rejected expense
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
