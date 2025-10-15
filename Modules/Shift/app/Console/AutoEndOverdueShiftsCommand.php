<?php

namespace Modules\Shift\Console;

use Illuminate\Console\Command;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutoEndOverdueShiftsCommand extends Command
{
    protected $signature = 'shifts:auto-end-overdue';
    protected $description = 'Automatically end shifts that are overdue';

    public function handle(): int
    {
        $this->info('Checking for overdue shifts...');

        $overdueThresholdHours = config('shift.timing.auto_end_shift_after_hours', 12);

        $overdueShifts = CashierShift::where('status', ShiftStatus::IN_PROGRESS)
            ->where('actual_start_time', '<=', now()->subHours($overdueThresholdHours))
            ->get();

        if ($overdueShifts->isEmpty()) {
            $this->info('No overdue shifts found.');
            return Command::SUCCESS;
        }

        foreach ($overdueShifts as $shift) {
            try {
                // Auto-end the shift with a note
                $shift->update([
                    'status' => ShiftStatus::COMPLETED,
                    'actual_end_time' => now(),
                    'handover_notes' => 'Auto-ended by system due to overtime',
                ]);

                // Log the action
                Log::warning('Shift auto-ended', [
                    'shift_id' => $shift->id,
                    'cashier_id' => $shift->cashier_id,
                    'duration_hours' => $shift->actual_start_time->diffInHours(now()),
                ]);

                // Notify branch manager
                $shift->shift->branch->manager->notify(
                    new ShiftAutoEndedNotification($shift)
                );

                $this->info("Shift ID {$shift->id} auto-ended successfully.");

            } catch (\Exception $e) {
                $this->error("Failed to auto-end shift ID {$shift->id}: {$e->getMessage()}");
            }
        }

        $this->info("Total overdue shifts processed: {$overdueShifts->count()}");

        return Command::SUCCESS;
    }
}
