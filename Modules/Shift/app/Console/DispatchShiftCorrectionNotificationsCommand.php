<?php

namespace Modules\Shift\Console;

use Illuminate\Console\Command;
use Modules\Shift\Services\ShiftReportCorrectionNotificationService;

class DispatchShiftCorrectionNotificationsCommand extends Command
{
    protected $signature = 'shift:dispatch-correction-notifications {--revision= : Optional revision ID to dispatch}';

    protected $description = 'Dispatch pending shift report correction outbox notifications';

    public function handle(ShiftReportCorrectionNotificationService $service): int
    {
        $revision = $this->option('revision');
        $count = $service->dispatchPending($revision ? (string) $revision : null);

        $this->info("Dispatched {$count} shift report correction notification(s).");

        return self::SUCCESS;
    }
}
