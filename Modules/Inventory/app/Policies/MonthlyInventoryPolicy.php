<?php

namespace Modules\Inventory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Models\MonthlyInventory;

class MonthlyInventoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(BranchManager $user): bool
    {
        return (bool) $user->branch_id;
    }

    public function view(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id;
    }

    public function create(BranchManager $user): bool
    {
        return (bool) $user->branch_id;
    }

    public function update(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id
            && $user->id === $inventory->created_by
            && $inventory->status->isEditable();
    }

    public function delete(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id
            && $user->id === $inventory->created_by
            && $inventory->status->isEditable();
    }

    public function submit(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id
            && $user->id === $inventory->created_by
            && $inventory->status->canSubmit();
    }

    public function approve(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id
            && in_array($inventory->status->value, ['submitted', 'pending_finance_review'], true);
    }

    public function returnToDraft(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id
            && in_array($inventory->status->value, ['submitted', 'pending_finance_review'], true);
    }

    public function export(BranchManager $user, MonthlyInventory $inventory): bool
    {
        return $user->branch_id === $inventory->branch_id;
    }
}
