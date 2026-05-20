<?php

namespace Modules\Shift\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\CashierShift;

class ShiftPolicy
{
    use HandlesAuthorization;

    public function viewAny(BranchManager $manager): bool
    {
        return true;
    }

    public function view(BranchManager $manager, CashierShift $shift): bool
    {
        return $shift->shift->branch_id === $manager->branch_id;
    }

    public function reassign(BranchManager $manager, CashierShift $shift): bool
    {
        return $shift->shift->branch_id === $manager->branch_id;
    }

    public function endShift(BranchManager $manager, CashierShift $shift): bool
    {
        return $shift->shift->branch_id === $manager->branch_id
            && in_array($shift->status->value, ['in_progress']);
    }

    public function approveHandover(BranchManager $manager, CashierShift $shift): bool
    {
        return $shift->shift->branch_id === $manager->branch_id
            && $shift->handoverStatus?->status->value === 'pending';
    }

    public function rejectHandover(BranchManager $manager, CashierShift $shift): bool
    {
        return $shift->shift->branch_id === $manager->branch_id
            && $shift->handoverStatus?->status->value === 'pending';
    }
}
