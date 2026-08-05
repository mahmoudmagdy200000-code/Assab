<?php

namespace Modules\Cashier\Observers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Cashier\Events\CashierActivatedEvent;
use Modules\Cashier\Events\CashierDeactivatedEvent;
use Modules\Cashier\Models\Cashier;

class CashierObserver
{
    public function creating(Cashier $cashier): void
    {
        // Set default values
        if (! $cashier->status) {
            $cashier->status = 'pending';
        }
    }

    public function created(Cashier $cashier): void
    {
        Log::info('Cashier created', [
            'cashier_id' => $cashier->id,
            'email' => $cashier->email,
            'branch_id' => $cashier->branch_id,
        ]);
    }

    public function updated(Cashier $cashier): void
    {
        Log::info('Cashier updated', [
            'cashier_id' => $cashier->id,
            'changes' => $cashier->getChanges(),
        ]);

        // Status side effects fire AFTER the row is written. They used to run on
        // `updating`: a deactivation revoked tokens and cancelled shifts before
        // the cashier row existed in that state, so a failure further down the
        // save left an active cashier with a cancelled schedule.
        if (! $cashier->wasChanged('status')) {
            return;
        }

        $oldStatus = $cashier->getOriginal('status');
        $newStatus = $cashier->status;

        if ($newStatus === 'active' && $oldStatus !== 'active') {
            event(new CashierActivatedEvent($cashier));
        }

        if ($newStatus === 'deactivated' && $oldStatus !== 'deactivated') {
            event(new CashierDeactivatedEvent($cashier));
        }
    }

    public function deleting(Cashier $cashier): void
    {
        // Check if cashier has active shifts
        if ($cashier->hasActiveShift()) {
            throw new \Exception('Cannot delete cashier with active shifts');
        }
    }

    public function deleted(Cashier $cashier): void
    {
        Log::info('Cashier deleted', [
            'cashier_id' => $cashier->id,
            'email' => $cashier->email,
        ]);

        // Delete profile image
        if ($cashier->image) {
            Storage::disk('public')->delete($cashier->image);
        }

        // Revoke all tokens
        $cashier->tokens()->delete();
    }

    public function restored(Cashier $cashier): void
    {
        Log::info('Cashier restored', [
            'cashier_id' => $cashier->id,
            'email' => $cashier->email,
        ]);
    }
}
