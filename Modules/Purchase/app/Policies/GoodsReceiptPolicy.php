<?php

namespace Modules\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\GoodsReceipt;

class GoodsReceiptPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any receipts.
     */
    public function viewAny(BranchManager $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the receipt.
     */
    public function view(BranchManager $user, GoodsReceipt $receipt): bool
    {
        return $user->branch_id === $receipt->branch_id;
    }

    /**
     * Determine whether the user can create receipts.
     */
    public function create(BranchManager $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the receipt.
     */
    public function update(BranchManager $user, GoodsReceipt $receipt): bool
    {
        return $user->branch_id === $receipt->branch_id &&
               in_array($receipt->status, ['draft', 'in_progress']);
    }

    /**
     * Determine whether the user can delete the receipt.
     */
    public function delete(BranchManager $user, GoodsReceipt $receipt): bool
    {
        return $user->branch_id === $receipt->branch_id && $receipt->status === 'draft';
    }

    /**
     * Determine whether the user can complete the receipt.
     */
    public function complete(BranchManager $user, GoodsReceipt $receipt): bool
    {
        return $user->branch_id === $receipt->branch_id && $receipt->status === 'in_progress';
    }
}
