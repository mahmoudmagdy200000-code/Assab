<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\CashierShiftHandover;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BranchManagerService
{
    public function assignedActiveManager(string $branchId): BranchManager
    {
        $managers = BranchManager::query()->active()->byBranch($branchId)
            ->orderBy('id')->limit(2)->get();

        if ($managers->isEmpty()) {
            throw new ConflictHttpException('BRANCH_MANAGER_RECIPIENT_UNAVAILABLE');
        }
        if ($managers->count() !== 1) {
            throw new ConflictHttpException('BRANCH_MANAGER_RECIPIENT_AMBIGUOUS');
        }

        return $managers->first();
    }

    public function assertAssignedActiveManager(string $branchId, string $managerId): BranchManager
    {
        $manager = $this->assignedActiveManager($branchId);
        if ((string) $manager->id !== (string) $managerId) {
            throw new AccessDeniedHttpException('ONLY_ASSIGNED_BRANCH_MANAGER_RECIPIENT');
        }

        return $manager;
    }

    /** Domain guard for create, reactivation, restore, and branch transfer. */
    public function assertActiveAssignmentAvailable(BranchManager $manager): void
    {
        if (! $manager->is_active || $manager->status !== 'active' || ! $manager->branch_id) {
            return;
        }

        $another = BranchManager::query()->active()->byBranch($manager->branch_id)
            ->when($manager->exists, fn ($query) => $query->where('id', '<>', $manager->id))
            ->exists();

        if ($another) {
            throw ValidationException::withMessages([
                'branch_id' => ['BRANCH_ACTIVE_MANAGER_ALREADY_ASSIGNED'],
            ]);
        }
    }

    /** Prevent lifecycle changes from orphaning an actionable manager handover. */
    public function assertNoOpenAddressedHandovers(BranchManager $manager): void
    {
        $hasOpenHandover = CashierShiftHandover::query()
            ->forManager($manager->id)
            ->whereIn('status', ['pending', 'rejected'])
            ->whereDoesntHave('receipt')
            ->exists();

        if ($hasOpenHandover) {
            throw new ConflictHttpException('MANAGER_HAS_OPEN_HANDOVERS');
        }
    }
}
