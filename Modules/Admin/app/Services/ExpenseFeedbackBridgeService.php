<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Reverse leg of the expense bridge (WS1b) — RETIRED by BRC-03 / EXP-06 (TD-2).
 *
 * A dashboard accountant / head decision used to be written back onto the
 * legacy `expenses` row. That path bypassed every custody debit / reversal
 * (PRB-05), so it is now closed: an expense is decided ONLY by the brand owner
 * in the mobile flow, and the dashboard operation is a read-only mirror whose
 * decisions OperationService refuses outright (409 EXPENSE_DECISION_NOT_AUTHORIZED).
 *
 * `syncFromOperation()` is kept as an explicit fail-closed no-op: it is the
 * documented contract that a dashboard operation decision performs no legacy
 * expense write, and the regression tests (TwoWorldFeedbackLoopTest,
 * ExpenseApprovalChainTest, ExpenseDecisionAuthorityTest) assert exactly that.
 * The former accountant-cycle write-back body and its helpers were removed in
 * the PKG-2 cleanup. The forward (read) bridge — ExpenseBridgeService /
 * SyncLegacyExpenseOperation — and the shift / purchase bridges are untouched.
 */
class ExpenseFeedbackBridgeService
{
    /**
     * No-op by design: a dashboard operation decision must never write the
     * legacy expense row. Kept as the explicit, tested contract of the retired
     * reverse bridge.
     *
     * @param  AsabUser|null  $actor  the acting dashboard user (ignored)
     */
    public function syncFromOperation(Operation $op, ?AsabUser $actor = null): void
    {
        // Intentionally does nothing — see the class docblock (BRC-03 / EXP-06).
    }
}
