<?php

namespace Modules\Shift\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\BranchManagerShift;

/**
 * A branch manager started their workday in the mobile app. Counterpart of
 * ShiftStartedEvent (cashier): the dashboard's live shift board mirrors the
 * running manager shift from here, so «مين شغال دلوقتي» includes the manager.
 */
class ManagerShiftStartedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public BranchManagerShift $managerShift) {}
}
