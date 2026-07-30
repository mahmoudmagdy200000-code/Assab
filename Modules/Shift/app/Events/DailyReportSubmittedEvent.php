<?php

namespace Modules\Shift\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\BranchManagerShift;

/**
 * Fired when a branch manager submits the final daily close (daily report) on
 * the mobile app — the ASAB bridge turns it into the branch's daily sales
 * statement (`module_key='sales'` operation) for accountant review.
 */
class DailyReportSubmittedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BranchManagerShift $managerShift
    ) {}
}
