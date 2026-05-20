<?php

namespace Modules\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\ReturnOrder;

class ReturnOrderPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any returns.
     */
    public function viewAny(BranchManager $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the return.
     */
    public function view(BranchManager $user, ReturnOrder $return): bool
    {
        return $user->branch_id === $return->branch_id ||
               $user->id === $return->created_by;
    }

    /**
     * Determine whether the user can create returns.
     */
    public function create(BranchManager $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the return.
     */
    public function update(BranchManager $user, ReturnOrder $return): bool
    {
        return $user->id === $return->created_by && $return->status === ReturnStatus::DRAFT;
    }

    /**
     * Determine whether the user can delete the return.
     */
    public function delete(BranchManager $user, ReturnOrder $return): bool
    {
        return $user->id === $return->created_by && $return->status === ReturnStatus::DRAFT;
    }

    /**
     * Determine whether the user can submit the return.
     */
    public function submit(BranchManager $user, ReturnOrder $return): bool
    {
        return $user->id === $return->created_by && $return->status === ReturnStatus::DRAFT;
    }

    /**
     * Determine whether the user can escalate the return.
     */
    public function escalate(BranchManager $user, ReturnOrder $return): bool
    {
        return $user->id === $return->created_by &&
               $return->status === ReturnStatus::REJECTED;
    }

    /**
     * Determine whether the user can accept rejection.
     */
    public function acceptRejection(BranchManager $user, ReturnOrder $return): bool
    {
        return $user->id === $return->created_by &&
               $return->status === ReturnStatus::REJECTED;
    }
}
