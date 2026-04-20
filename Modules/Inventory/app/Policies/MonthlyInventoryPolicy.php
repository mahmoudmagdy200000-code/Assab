<?php

namespace Modules\Inventory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Models\MonthlyInventory;

class MonthlyInventoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(BranchManager|Cashier $user): bool
    {
        return (bool) ($user->branch_id ?? null);
    }

    public function view(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        if (($user->branch_id ?? null) !== $inventory->branch_id) {
            return false;
        }
        if ($user instanceof Cashier) {
            return $inventory->staff()->where('user_id', $user->id)->where('user_type', Cashier::class)->exists();
        }
        return true;
    }

    public function create(BranchManager|Cashier $user): bool
    {
        return $user instanceof BranchManager && (bool) $user->branch_id;
    }

    public function update(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        if ($user instanceof Cashier) {
            return $user->branch_id === $inventory->branch_id
                && $inventory->staff()->where('user_id', $user->id)->where('user_type', Cashier::class)->exists()
                && $inventory->status->isEditable();
        }
        return $user->branch_id === $inventory->branch_id
            && $user->id === $inventory->created_by
            && $inventory->status->isEditable();
    }

    public function delete(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        return $user instanceof BranchManager
            && $user->branch_id === $inventory->branch_id
            && $user->id === $inventory->created_by
            && $inventory->status->isEditable();
    }

    public function submit(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        if (($user->branch_id ?? null) !== $inventory->branch_id) {
            return false;
        }
        if ($user instanceof Cashier) {
            return $inventory->staff()->where('user_id', $user->id)->where('user_type', Cashier::class)->exists()
                && $inventory->status->canSubmit();
        }
        return $user->id === $inventory->created_by && $inventory->status->canSubmit();
    }

    public function approve(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        return $user instanceof BranchManager
            && $user->branch_id === $inventory->branch_id
            && in_array($inventory->status->value, ['submitted', 'pending_finance_review'], true);
    }

    public function confirmSubmission(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        return $user instanceof BranchManager
            && $user->branch_id === $inventory->branch_id
            && $inventory->status->value === 'pending_your_confirmation';
    }

    public function returnToDraft(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        return $user instanceof BranchManager
            && $user->branch_id === $inventory->branch_id
            && in_array($inventory->status->value, ['submitted', 'pending_finance_review'], true);
    }

    public function export(BranchManager|Cashier $user, MonthlyInventory $inventory): bool
    {
        return $user instanceof BranchManager && $user->branch_id === $inventory->branch_id;
    }
}
