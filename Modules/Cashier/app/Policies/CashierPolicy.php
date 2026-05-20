<?php

namespace Modules\Cashier\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class CashierPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view any cashiers
     */
    public function viewAny(BranchManager $manager): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the cashier
     */
    public function view(BranchManager $manager, Cashier $cashier): bool
    {
        return $manager->branch_id === $cashier->branch_id;
    }

    /**
     * Determine if the user can create cashiers
     */
    public function create(BranchManager $manager): bool
    {
        return true;
    }

    /**
     * Determine if the user can update the cashier
     */
    public function update(BranchManager $manager, Cashier $cashier): bool
    {
        return $manager->branch_id === $cashier->branch_id;
    }

    /**
     * Determine if the user can delete the cashier
     */
    public function delete(BranchManager $manager, Cashier $cashier): bool
    {
        return $manager->branch_id === $cashier->branch_id
            && ! $cashier->hasActiveShift();
    }

    /**
     * Determine if the user can restore the cashier
     */
    public function restore(BranchManager $manager, Cashier $cashier): bool
    {
        return $manager->branch_id === $cashier->branch_id;
    }

    /**
     * Determine if the user can permanently delete the cashier
     */
    public function forceDelete(BranchManager $manager, Cashier $cashier): bool
    {
        return false; // Not allowed
    }
}
