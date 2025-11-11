<?php

namespace Modules\Shift\Listeners;

use Modules\Shift\Models\{CashierShift, BranchManagerShift};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class BranchManagerShiftListener implements ShouldQueue
{
    /**
     * Handle cashier shift updated event
     */
    public function handle($event)
    {
        try {
            $cashierShift = $event->cashierShift ?? $event;

            if (!$cashierShift instanceof CashierShift) {
                return;
            }

            // Get branch manager for this cashier
            $branchManager = $cashierShift->shift->branch->branchManager;

            if (!$branchManager) {
                Log::warning('No branch manager found for cashier shift', [
                    'cashier_shift_id' => $cashierShift->id,
                ]);
                return;
            }

            // Get or create branch manager shift for this date
            $managerShift = BranchManagerShift::firstOrCreate([
                'branch_manager_id' => $branchManager->id,
                'shift_date' => $cashierShift->shift_date,
            ], [
                'branch_id' => $cashierShift->shift->branch_id,
                'status' => 'not_started',
            ]);

            // Update statistics
            $managerShift->updateStatistics();

            // If all cashier shifts are completed, aggregate sales data
            if ($managerShift->pending_cashier_shifts === 0 &&
                $managerShift->completed_cashier_shifts > 0) {
                $managerShift->aggregateSalesData();
            }

            Log::info('Branch manager shift updated successfully', [
                'manager_shift_id' => $managerShift->id,
                'cashier_shift_id' => $cashierShift->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update branch manager shift', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
