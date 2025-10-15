<?php

namespace Modules\Shift\Observers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Events\ShiftEndedEvent;

class CashierShiftObserver
{
    public function creating(CashierShift $shift): void
    {
        // Set default opening balance if not set
        if (is_null($shift->opening_balance)) {
            $shift->opening_balance = 0;
        }
    }

    public function created(CashierShift $shift): void
    {
        // Log creation
        Log::info('Shift created', [
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'shift_date' => $shift->shift_date,
        ]);
    }

    public function updating(CashierShift $shift): void
    {
        // Check if status changed to completed
        if ($shift->isDirty('status') && $shift->status->value === 'completed') {
            event(new ShiftEndedEvent($shift, !is_null($shift->next_cashier_id)));
        }
    }

    public function updated(CashierShift $shift): void
    {
        // Log update
        Log::info('Shift updated', [
            'shift_id' => $shift->id,
            'changes' => $shift->getChanges(),
        ]);
    }

    public function deleted(CashierShift $shift): void
    {
        // Clean up related files
        if ($shift->pos_receipt) {
            Storage::disk('public')->delete($shift->pos_receipt);
        }
    }
}
