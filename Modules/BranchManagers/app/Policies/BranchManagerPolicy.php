<?php

namespace Modules\BranchManagers\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the manager can view the profile
     */
    public function viewProfile(BranchManager $manager, BranchManager $target): bool
    {
        return $manager->id === $target->id;
    }

    /**
     * Determine if the manager can update the profile
     */
    public function updateProfile(BranchManager $manager, BranchManager $target): bool
    {
        return $manager->id === $target->id && $manager->is_active;
    }

    /**
     * Determine if the manager can manage cashiers
     */
    public function manageCashiers(BranchManager $manager): bool
    {
        return $manager->is_active && ! $manager->isSuspended();
    }

    /**
     * Determine if the manager can manage shifts
     */
    public function manageShifts(BranchManager $manager): bool
    {
        return $manager->is_active && ! $manager->isSuspended();
    }

    /**
     * Determine if the manager can manage expenses
     */
    public function manageExpenses(BranchManager $manager): bool
    {
        return $manager->is_active && ! $manager->isSuspended();
    }

    /**
     * Determine if the manager can view reports
     */
    public function viewReports(BranchManager $manager): bool
    {
        return $manager->is_active;
    }

    /**
     * Determine if the manager can manage settings
     */
    public function manageSettings(BranchManager $manager): bool
    {
        return $manager->is_active;
    }
}
