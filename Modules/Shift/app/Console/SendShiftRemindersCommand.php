<?php

namespace Modules\Shift\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Jobs\SendShiftStartReminderJob;
use Modules\Shift\Models\CashierShift;

class SendShiftRemindersCommand extends Command
{
    protected $signature = 'shifts:send-reminders';

    protected $description = 'Send shift start reminders to cashiers';

    public function handle(): int
    {
        $this->info('Sending shift start reminders...');

        // Get shifts starting in the next 15 minutes
        $shifts = CashierShift::where('status', ShiftStatus::NOT_STARTED)
            ->whereDate('shift_date', today())
            ->get()
            ->filter(function ($shift) {
                $shiftStart = Carbon::parse($shift->shift->start_time);
                $minutesUntilStart = now()->diffInMinutes($shiftStart, false);

                return $minutesUntilStart > 0 && $minutesUntilStart <= 15;
            });

        foreach ($shifts as $shift) {
            SendShiftStartReminderJob::dispatch($shift);
            $this->info("Reminder queued for Shift ID: {$shift->id}");
        }

        $this->info("Total reminders sent: {$shifts->count()}");

        return Command::SUCCESS;
    }
}
