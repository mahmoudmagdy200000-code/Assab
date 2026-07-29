<?php

namespace Modules\Shift\Observers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Events\ShiftStartedEvent;
use Modules\Shift\Listeners\BranchManagerShiftListener;
use Modules\Shift\Models\CashierShift;

class CashierShiftObserver
{
    protected $managerShiftListener;

    public function __construct()
    {
        $this->managerShiftListener = new BranchManagerShiftListener;
    }

    /**
     * Handle the CashierShift "creating" event.
     */
    public function creating(CashierShift $shift): void
    {
        // Set default opening balance if not set
        if (is_null($shift->opening_balance)) {
            $shift->opening_balance = 0;
        }
    }

    /**
     * Handle the CashierShift "created" event.
     */
    public function created(CashierShift $shift): void
    {
        // Log creation
        Log::info('Shift created', [
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'shift_date' => $shift->shift_date,
        ]);

        // Update branch manager shift statistics
        $this->updateManagerShift($shift);
    }

    /**
     * Handle the CashierShift "updating" event.
     */
    public function updating(CashierShift $shift): void
    {
        // Check if status changed to completed
        if ($shift->isDirty('status') && $shift->status->value === 'completed') {
            event(new ShiftEndedEvent($shift, ! is_null($shift->next_cashier_id)));
        }

        // A shift going live feeds the dashboard's «مباشر» board in real time.
        if ($shift->isDirty('status') && $shift->status->value === 'in_progress') {
            event(new ShiftStartedEvent($shift));
        }
    }

    /**
     * Handle the CashierShift "updated" event.
     */
    public function updated(CashierShift $shift): void
    {
        // Log update
        Log::info('Shift updated', [
            'shift_id' => $shift->id,
            'changes' => $shift->getChanges(),
        ]);

        // Update branch manager shift if status changed
        if ($shift->wasChanged('status')) {
            $this->updateManagerShift($shift);
        }
    }

    /**
     * Handle the CashierShift "deleted" event.
     */
    public function deleted(CashierShift $shift): void
    {
        // Clean up related files
        if ($shift->pos_receipt) {
            Storage::disk('public')->delete($shift->pos_receipt);
        }

        // Update branch manager shift statistics
        $this->updateManagerShift($shift);
    }

    /**
     * Update branch manager shift statistics
     */
    private function updateManagerShift(CashierShift $cashierShift): void
    {
        try {
            $this->managerShiftListener->handle($cashierShift);
        } catch (\Exception $e) {
            // Log error but don't fail the main operation
            Log::error('Failed to update manager shift in observer', [
                'shift_id' => $cashierShift->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
