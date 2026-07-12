<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Services\ShiftLatenessService;

/**
 * SRS ACC-6.2 — flip active shifts whose configured window has elapsed to
 * `late`. Scheduled every 15 minutes (Asia/Riyadh) in AdminServiceProvider.
 */
class MarkLateShiftsCommand extends Command
{
    protected $signature = 'asab:shifts-mark-late';

    protected $description = 'Mark overdue active ASAB shifts as late (ACC-6.2)';

    public function handle(ShiftLatenessService $lateness): int
    {
        $count = $lateness->markLate();
        $this->info("Marked {$count} shift(s) late.");

        return self::SUCCESS;
    }
}
