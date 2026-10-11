<?php

namespace Modules\Shift\Observers;

use Illuminate\Support\Facades\DB;
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
        // A template's configured float is reference data, not money received.
        // The sole receipt writer projects confirmed opening after confirmation.
        $shift->opening_balance = '0.00';
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
            $event = new ShiftEndedEvent($shift, ! is_null($shift->next_cashier_id));
            $shiftId = $shift->getKey();
            DB::afterCommit(function () use ($event, $shiftId): void {
                try {
                    event($event);
                } catch (\Throwable $exception) {
                    Log::error('Cashier shift close projection failed after commit', [
                        'shift_id' => $shiftId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }

        // A shift going live feeds the dashboard's «مباشر» board in real time.
        if ($shift->isDirty('status') && $shift->status->value === 'in_progress') {
            $event = new ShiftStartedEvent($shift);
            $shiftId = $shift->getKey();
            DB::afterCommit(function () use ($event, $shiftId): void {
                try {
                    event($event);
                } catch (\Throwable $exception) {
                    Log::error('Cashier shift start projection failed after commit', [
                        'shift_id' => $shiftId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
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
        $shiftId = $cashierShift->getKey();

        // This is a non-authoritative dashboard/statistics projection. Run it
        // only after the financial transaction releases the cashier row; the
        // manager close path locks manager -> cashier, so doing the reverse
        // here could form a deadlock cycle.
        DB::afterCommit(function () use ($cashierShift, $shiftId): void {
            try {
                $committedShift = CashierShift::query()->find($shiftId) ?? $cashierShift;
                $this->managerShiftListener->handle($committedShift);
            } catch (\Throwable $e) {
                // This projection must not turn a committed financial write into
                // a reported failure.
                Log::error('Failed to update manager shift in observer', [
                    'shift_id' => $shiftId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
