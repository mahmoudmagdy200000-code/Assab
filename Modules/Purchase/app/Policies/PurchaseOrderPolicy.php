<?php

namespace Modules\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\PurchaseOrder;

class PurchaseOrderPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any orders.
     */
    public function viewAny(BranchManager $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the order.
     */
    public function view(BranchManager $user, PurchaseOrder $order): bool
    {
        return $user->branch_id === $order->branch_id || 
               $user->id === $order->requested_by;
    }

    /**
     * Determine whether the user can create orders.
     */
    public function create(BranchManager $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the order.
     */
    public function update(BranchManager $user, PurchaseOrder $order): bool
    {
        return ($user->branch_id === $order->branch_id || $user->id === $order->requested_by) 
               && $order->status->isActive();
    }

    /**
     * Determine whether the user can delete the order.
     */
    public function delete(BranchManager $user, PurchaseOrder $order): bool
    {
        return $user->id === $order->requested_by && $order->status->value === 'draft';
    }

    /**
     * Determine whether the user can submit the order.
     */
    public function submit(BranchManager $user, PurchaseOrder $order): bool
    {
        return $user->id === $order->requested_by && $order->status->value === 'draft';
    }

    /**
     * Determine whether the user can approve the order.
     */
    public function approve(BranchManager $user, PurchaseOrder $order): bool
    {
        // For internal transfers, the receiving branch manager can approve
        if ($order->order_type->isTransfer()) {
            return $user->branch_id === $order->from_branch_id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can reject the order.
     */
    public function reject(BranchManager $user, PurchaseOrder $order): bool
    {
        return $this->approve($user, $order);
    }

    /**
     * Determine whether the user can cancel the order.
     */
    public function cancel(BranchManager $user, PurchaseOrder $order): bool
    {
        return $user->id === $order->requested_by && $order->status->isActive();
    }

    /**
     * Determine whether the user can receive the order.
     */
    public function receive(BranchManager $user, PurchaseOrder $order): bool
    {
        return $user->branch_id === $order->branch_id && $order->can_receive;
    }
}

