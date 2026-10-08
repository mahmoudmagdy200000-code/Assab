<?php

namespace Modules\BranchManagers\Observers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BranchManagers\Events\BranchManagerSuspendedEvent;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;

class BranchManagerObserver
{
    public function creating(BranchManager $manager): void
    {
        // Set default values
        if (! isset($manager->status)) {
            $manager->status = 'active';
        }

        if (! isset($manager->is_active)) {
            $manager->is_active = true;
        }

        if (! isset($manager->is_first_login)) {
            $manager->is_first_login = true;
        }

        app(BranchManagerService::class)->assertActiveAssignmentAvailable($manager);
    }

    public function created(BranchManager $manager): void
    {
        Log::info('Branch Manager created', [
            'manager_id' => $manager->id,
            'email' => $manager->email,
            'branch_id' => $manager->branch_id,
        ]);
    }

    public function updating(BranchManager $manager): void
    {
        if ($manager->isDirty(['branch_id', 'status', 'is_active'])) {
            app(BranchManagerService::class)->assertActiveAssignmentAvailable($manager);
        }

        // Detect status changes
        if ($manager->isDirty('status')) {
            $oldStatus = $manager->getOriginal('status');
            $newStatus = $manager->status;

            if ($newStatus === 'suspended' && $oldStatus !== 'suspended') {
                event(new BranchManagerSuspendedEvent($manager, 'Status changed to suspended'));
            }
        }

        // Detect deactivation
        if ($manager->isDirty('is_active')) {
            $oldActive = $manager->getOriginal('is_active');
            $newActive = $manager->is_active;

            if (! $newActive && $oldActive) {
                // Manager deactivated
                Log::warning('Branch Manager deactivated', [
                    'manager_id' => $manager->id,
                    'email' => $manager->email,
                ]);
            }
        }
    }

    public function updated(BranchManager $manager): void
    {
        Log::info('Branch Manager updated', [
            'manager_id' => $manager->id,
            'changes' => $manager->getChanges(),
        ]);
    }

    public function restoring(BranchManager $manager): void
    {
        app(BranchManagerService::class)->assertActiveAssignmentAvailable($manager);
    }

    public function deleted(BranchManager $manager): void
    {
        Log::warning('Branch Manager deleted', [
            'manager_id' => $manager->id,
            'email' => $manager->email,
        ]);

        // Delete profile image
        if ($manager->image) {
            Storage::disk('public')->delete($manager->image);
        }

        // Revoke all tokens
        $manager->tokens()->delete();
    }

    public function restored(BranchManager $manager): void
    {
        Log::info('Branch Manager restored', [
            'manager_id' => $manager->id,
            'email' => $manager->email,
        ]);
    }
}
