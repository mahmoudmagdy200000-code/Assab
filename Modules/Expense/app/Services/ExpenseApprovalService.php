<?php

namespace Modules\Expense\Services;

use Illuminate\Support\Facades\DB;
use Modules\Custody\Services\ExpenseCustodyService;
use Modules\Expense\Enums\ExpenseApprovalStage;
use Modules\Expense\Enums\ExpenseDecisionRole;
use Modules\Expense\Enums\ExpenseTimelinePerformedByType;
use Modules\Expense\Models\Expense;

/**
 * Expense Approval Service
 * Handles approval/rejection by Brand Owner.
 *
 * The brand owner is ONE of the two approval cycles (meeting 2026-08-14): the
 * dashboard accountant may take the same pending expense, and whoever moves
 * first owns it. `assertBrandOwnerMayDecide` is the guard for that race — the
 * dashboard side is guarded by the operation's own state machine.
 *
 * Custody (BRC v3.4 F-01, EXP-02…EXP-08): an expense paid from branch custody is charged ONCE when it is
 * submitted / resubmitted, confirmed (never charged again) by approval and reversed ONCE by a rejection —
 * see {@see ExpenseCustodyService}. Each decision runs in one transaction with the row locked and its status
 * re-checked, so the status change, the audit row and the custody movement succeed or roll back together and
 * two simultaneous decisions cannot both take effect (EXP-07). Events fire after that transaction.
 */
class ExpenseApprovalService
{
    /** The expense row, locked for the duration of the surrounding transaction and read fresh (EXP-07). */
    private function lockFresh(Expense $expense): Expense
    {
        return Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Submit expense for approval
     */
    public function submitExpense(Expense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $current = $this->lockFresh($expense);

            // Already pending (created non-draft, or a client retry): re-submitting is a no-op on the
            // record and on custody (nothing is charged twice); the event is re-fired below so the ASAB
            // bridge (idempotent) can catch anything that slipped — never 500 the client for it.
            if ($current->status === 'pending') {
                return;
            }

            if ($current->status !== 'draft') {
                throw new \Exception('Only draft expenses can be submitted');
            }

            // F-04: a draft may be captured without a resolved owner, but submitting
            // it makes it financially accountable — block the transition unless the
            // immutable origin (branch+brand+company) is complete and consistent
            // (never guessed from the manager's current branch). Throws before any write.
            ExpenseOriginGuard::assertStoredOriginUsable($current, 'submitted');

            // EXP-02 / EXP-05: charge the branch custody once, or refuse the submission.
            app(ExpenseCustodyService::class)->reserve($current);

            $current->update([
                'status' => 'pending',
                'submitted_at' => now(),
            ]);

            // Create timeline entry
            $this->createTimelineEntry($current, 'submit', 'submitted');
        });

        // Fire event for notifications
        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->refresh()));
    }

    /**
     * Approve expense (Brand Owner or Brand Manager — `$role` is who actually decided).
     *
     * The approval STAGE stays `brand_owner_approved` for both (a state-machine value shared
     * with the clients); the actor is attributed in `decided_by_role` and in the timeline row's
     * `performed_by_type` (`brand_owner` vs `brand_manager`, see G-1 migration), while
     * `performed_by` always carries the real actor id.
     *
     * Terminal for everyone: the accountant and the head of accounts see the
     * record as «موافق عليه من <العلامة التجارية>» and can take no action on it.
     */
    public function approveExpense(
        Expense $expense,
        string $brandOwnerId,
        ?string $brandOwnerName = null,
        ExpenseDecisionRole $role = ExpenseDecisionRole::BRAND_OWNER
    ): void {
        DB::transaction(function () use ($expense, $brandOwnerId, $brandOwnerName, $role): void {
            // EXP-07: the first valid decision on the CURRENT version wins — decide on the locked, fresh row.
            $current = $this->lockFresh($expense);
            $this->assertBrandOwnerMayDecide($current, 'approved');

            // F-04: never commit (approve) an expense whose immutable origin is
            // unresolved or inconsistent — it would be invisible to the owning brand's
            // readers and escape brand-scoped accountability. Rejection stays allowed
            // so the record can be sent back and fixed rather than stranded.
            ExpenseOriginGuard::assertStoredOriginUsable($current, 'approved');

            // EXP-02: approval CONFIRMS the charge taken at submission and never repeats it. Only an expense
            // submitted before the charge moved to submission has none yet; it is charged here, once, and an
            // uncovered one is refused (EXP-05) rather than spilled into sales cash.
            app(ExpenseCustodyService::class)->reserve($current);

            $current->update([
                'status' => 'approved',
                'approved_by' => $brandOwnerId,
                'approved_at' => now(),
                'approval_stage' => ExpenseApprovalStage::BRAND_OWNER_APPROVED,
                'decided_by_name' => $brandOwnerName,
                'decided_by_role' => $role->value,
                'decided_at' => now(),
            ]);

            // Create timeline entry
            $this->createTimelineEntry($current, 'approve', 'approved', $brandOwnerId, $this->timelinePerformedByType($role));
        });

        // Fire event for notifications + the ASAB bridge, which closes the
        // mirrored operation so the dashboard cannot decide it a second time.
        event(new \Modules\Expense\Events\ExpenseApprovedEvent($expense->refresh(), $brandOwnerId));
    }

    /**
     * Reject expense (Brand Owner). Terminal, like the approval above.
     */
    public function rejectExpense(
        Expense $expense,
        string $brandOwnerId,
        string $reason,
        ?string $brandOwnerName = null,
        ExpenseDecisionRole $role = ExpenseDecisionRole::BRAND_OWNER
    ): void {
        DB::transaction(function () use ($expense, $brandOwnerId, $reason, $brandOwnerName, $role): void {
            // EXP-07: decide on the locked, fresh row so a simultaneous approval / second rejection cannot also run.
            $current = $this->lockFresh($expense);
            $this->assertBrandOwnerMayDecide($current, 'rejected');

            // EXP-03: the charge taken at submission is reversed exactly once, in the same unit of work as the
            // status change and the audit row (the original and the reversal are both kept).
            app(ExpenseCustodyService::class)->reverse($current);

            $current->update([
                'status' => 'rejected',
                'rejected_by' => $brandOwnerId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'approval_stage' => ExpenseApprovalStage::BRAND_OWNER_REJECTED,
                'decided_by_name' => $brandOwnerName,
                'decided_by_role' => $role->value,
                'decided_at' => now(),
            ]);

            // Create timeline entry
            $this->createTimelineEntry($current, 'reject', 'rejected', $brandOwnerId, $this->timelinePerformedByType($role), $reason);
        });

        // Fire event for notifications
        event(new \Modules\Expense\Events\ExpenseRejectedEvent($expense->refresh(), $brandOwnerId, $reason));
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
        DB::transaction(function () use ($expense): void {
            $current = $this->lockFresh($expense);

            if ($current->status !== 'rejected') {
                throw new \Exception('Only rejected expenses can be resubmitted');
            }

            // F-04: resubmission re-enters the accountable cycle — same origin gate as submit.
            ExpenseOriginGuard::assertStoredOriginUsable($current, 'resubmitted');

            // EXP-08: a new review cycle of the SAME request charges the (possibly corrected) amount once —
            // the previous charge was reversed at rejection — or is refused when the custody cannot cover it.
            app(ExpenseCustodyService::class)->reserve($current);

            $current->update([
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
            $this->createTimelineEntry($current, 'resubmit', 'resubmitted');
        });

        // Fire event for notifications (same as submitted)
        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->refresh()));
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

    /**
     * Map the deciding role to the timeline actor type. Requires the G-1
     * migration (performed_by_type widened from enum to string) to be deployed.
     */
    private function timelinePerformedByType(ExpenseDecisionRole $role): string
    {
        return $role === ExpenseDecisionRole::BRAND_MANAGER
            ? ExpenseTimelinePerformedByType::BRAND_MANAGER->value
            : ExpenseTimelinePerformedByType::BRAND_OWNER->value;
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
