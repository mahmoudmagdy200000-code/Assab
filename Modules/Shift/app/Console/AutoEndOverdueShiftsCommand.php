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

/**
 * Hourly guard for a till left running (bootstrap/app.php).
 *
 * It used to notify `$shift->shift->branch->manager` with a
 * `ShiftAutoEndedNotification` — but `branches.manager` is a NAME STRING, not a
 * relation, and that notification class does not exist. Both raise an `Error`,
 * which `catch (\Exception)` does not catch: the command aborted on the FIRST
 * overdue shift (already flipped to `completed` and bridged to the dashboard by
 * then) and every later one was left running until the next hour repeated the
 * same crash. The manager is a `branch_managers` row; notifying is best-effort
 * and never decides whether the till gets closed.
 */
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

                $this->notifyManagers($shift);

                $this->info("Shift ID {$shift->id} auto-ended successfully.");

            } catch (\Throwable $e) {
                // \Throwable, not \Exception: an Error here used to abort the
                // whole run and leave every later till open.
                $this->error("Failed to auto-end shift ID {$shift->id}: {$e->getMessage()}");
                Log::error('Shift auto-end failed', ['shift_id' => $shift->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Total overdue shifts processed: {$overdueShifts->count()}");

        return Command::SUCCESS;
    }

    /** Best-effort in-app notice to the branch's managers. Never fails the close. */
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
                        'reason' => 'auto_ended_overtime',
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
