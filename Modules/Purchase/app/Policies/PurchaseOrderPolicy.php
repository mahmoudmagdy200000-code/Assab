<?php

namespace Modules\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseAuthorizationService;

class PurchaseOrderPolicy
{
    use HandlesAuthorization;

    private function authService(): PurchaseAuthorizationService
    {
        return app(PurchaseAuthorizationService::class);
    }

    /**
     * Determine whether the user can view any orders.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null;
    }

    /**
     * Determine whether the user can view the order.
     */
    public function view(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->authService()->canAccessPurchaseOrder($user, $order);
    }

    /**
     * Determine whether the user can create orders.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null;
    }

    /**
     * Determine whether the user can update the order.
     */
    public function update(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null
            && (string) $user->branch_id === (string) $order->branch_id
            && $order->status->isActive();
    }

    /**
     * Determine whether the user can delete the order.
     */
    public function delete(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null
            && (string) $user->branch_id === (string) $order->branch_id
            && $order->status->value === 'draft';
    }

    /**
     * Determine whether the user can submit the order.
     */
    public function submit(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null
            && (string) $user->branch_id === (string) $order->branch_id
            && $order->status->value === 'draft';
    }

    /**
     * Determine whether the user can approve the order.
     */
    public function approve(Authenticatable $user, PurchaseOrder $order): bool
    {
        // For internal transfers, only the sending branch (`from_branch_id`) manager can approve
        return $this->authService()->canDecideInternalTransfer($user, $order);
    }

    /**
     * Determine whether the user can reject the order.
     */
    public function reject(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->approve($user, $order);
    }

    /**
     * Determine whether the user can cancel the order.
     */
    public function cancel(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null
            && (string) $user->branch_id === (string) $order->branch_id
            && $order->status->isActive();
    }

    /**
     * Determine whether the user can receive the order.
     */
    public function receive(Authenticatable $user, PurchaseOrder $order): bool
    {
        return $this->authService()->canReceivePurchaseOrder($user, $order, false) && $order->can_receive;
    }
}
