<?php

namespace Modules\Shift\Policies;

use Illuminate\Database\Eloquent\Model;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * S1-11 R6 Policy: Authorizes settlement actors on confirmed handover receipts.
 *
 * Proposed scoped manager policy is inactive until the settlement authority
 * business decision is recorded. A manager assignment alone does not activate it.
 *
 * Policy boundary:
 * - Direct cashier settlement without manager supervision is NOT permitted.
 * - Broader accountant/admin settlement authority is tracked under
 *   SETTLEMENT_AUTHORITY_UNRESOLVED pending explicit executive business decision.
 */
class HandoverSettlementActorPolicy
{
    public const SETTLEMENT_AUTHORITY_UNRESOLVED = 'SETTLEMENT_AUTHORITY_UNRESOLVED';

    public function __construct(
        private ?BranchManagerService $branchManagerService = null
    ) {
        $this->branchManagerService = $branchManagerService ?? app(BranchManagerService::class);
    }

    /**
     * Assert actor has financial authority to settle the confirmed receipt.
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanSettle(Model $actor, CashierShiftHandoverReceipt $receipt, string $receiptBranchId): void
    {
        $this->assertPublicWriterAuthorized();
        if (! ($actor instanceof BranchManager)) {
            // Cashiers or unauthorized roles cannot execute financial settlements
            throw new AccessDeniedHttpException('UNAUTHORIZED_SETTLEMENT_ACTOR');
        }

        // Must be active and assigned to the branch of the receipt
        try {
            $this->branchManagerService->assertAssignedActiveManager($receiptBranchId, $actor->id);
        } catch (\Throwable $e) {
            throw new AccessDeniedHttpException('ONLY_ASSIGNED_BRANCH_MANAGER_MAY_SETTLE', $e);
        }

        if ((string) $actor->branch_id !== (string) $receiptBranchId) {
            throw new AccessDeniedHttpException('BRANCH_MANAGER_BRANCH_MISMATCH');
        }
    }

    public function assertPublicWriterAuthorized(): void
    {
        if (! config('shift.settlement_authority_approved', false)) {
            throw new AccessDeniedHttpException(self::SETTLEMENT_AUTHORITY_UNRESOLVED);
        }
    }

    /**
     * Assert actor has read authority on the receipt and its settlements.
     *
     * @throws AccessDeniedHttpException
     */
    public function assertCanReadReceipt(Model $actor, CashierShiftHandoverReceipt $receipt, string $receiptBranchId): void
    {
        if (! ($actor instanceof BranchManager)) {
            throw new AccessDeniedHttpException('UNAUTHORIZED_SETTLEMENT_READER');
        }

        try {
            $this->branchManagerService->assertAssignedActiveManager($receiptBranchId, (string) $actor->id);
        } catch (\Throwable $e) {
            throw new AccessDeniedHttpException('FORBIDDEN_BRANCH_RECEIPT_READ', $e);
        }

        if ((string) $actor->branch_id !== (string) $receiptBranchId) {
            throw new AccessDeniedHttpException('FORBIDDEN_BRANCH_RECEIPT_READ');
        }
    }

    public function canSettle(Model $actor, CashierShiftHandoverReceipt $receipt, string $receiptBranchId): bool
    {
        try {
            $this->assertCanSettle($actor, $receipt, $receiptBranchId);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }
}
