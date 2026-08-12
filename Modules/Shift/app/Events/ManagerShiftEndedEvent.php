<?php

namespace Modules\Shift\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\BranchManagerShift;

/**
 * A branch manager ended their workday in the mobile app. Closes the live
 * mirror row so the manager stops showing as «نشط» on the dashboard board.
 *
 * NOT the daily sales statement — that is DailyReportSubmittedEvent, which is
 * what actually reaches the accountant's المبيعات inbox.
 */
class ManagerShiftEndedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public BranchManagerShift $managerShift) {}
}
