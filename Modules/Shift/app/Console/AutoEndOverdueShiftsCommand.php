<?php

namespace Modules\Shift\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Notifications\BaseNotification;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;

/** Hourly overdue reminder. Elapsed time is never report submission or financial closure. */
class AutoEndOverdueShiftsCommand extends Command
{
    protected $signature = 'shifts:auto-end-overdue';

    protected $description = 'Notify managers about overdue shifts without financially closing them';

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
                // Timeout is an operational reminder. Only a submitted report may complete the shift.
                // No status/end time write: that would dispatch the financial ShiftEndedEvent/bridge.
                Log::warning('Shift overdue; financial report remains open', [
                    'shift_id' => $shift->id,
                    'cashier_id' => $shift->cashier_id,
                    'duration_hours' => $shift->actual_start_time->diffInHours(now()),
                ]);

                $this->notifyManagers($shift);

                $this->info("Shift ID {$shift->id} is overdue; report remains open.");

            } catch (\Throwable $e) {
                // \Throwable, not \Exception: an Error here used to abort the
                // whole run and leave every later till open.
                $this->error("Failed to process overdue shift ID {$shift->id}: {$e->getMessage()}");
                Log::error('Overdue shift reminder failed', ['shift_id' => $shift->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Total overdue shifts processed: {$overdueShifts->count()}");

        return Command::SUCCESS;
    }

    /** Best-effort in-app overdue notice. No financial transition. */
    private function notifyManagers(CashierShift $shift): void
    {
        try {
            $branchId = $shift->shift?->branch_id;
            if ($branchId === null) {
                return;
            }

            foreach (BranchManager::where('branch_id', $branchId)->get() as $manager) {
                $manager->notify(new BaseNotification(
                    NotificationType::SHIFT_END_REMINDER,
                    [
                        'shift_id' => $shift->id,
                        'cashier_id' => $shift->cashier_id,
                        'reason' => 'overdue_report_open',
                    ],
                    NotificationPriority::HIGH,
                    [NotificationChannel::IN_APP->value],
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Shift auto-end: manager notification failed', [
                'shift_id' => $shift->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
