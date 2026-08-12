<?php

namespace Modules\Shift\Observers;

use Modules\Shift\Events\ManagerShiftEndedEvent;
use Modules\Shift\Events\ManagerShiftStartedEvent;
use Modules\Shift\Models\BranchManagerShift;

/**
 * The manager half of the two-worlds shift bridge. `BranchManagerShift` is
 * written from several places (the start/end endpoints, the demo top-up
 * command), so the event is raised from the model's lifecycle rather than from
 * one controller — exactly how CashierShiftObserver does it for cashiers.
 *
 * `updated` (not `updating`): the mirror is written from the committed state,
 * so a failed save can never leave a live row on the dashboard board.
 */
class BranchManagerShiftObserver
{
    public function updated(BranchManagerShift $shift): void
    {
        if (! $shift->wasChanged('status')) {
            return;
        }

        if ($shift->status === 'in_progress') {
            event(new ManagerShiftStartedEvent($shift));

            return;
        }

        if ($shift->status === 'completed') {
            event(new ManagerShiftEndedEvent($shift));
        }
    }

    /** A row created straight into `in_progress` (seeders, demo top-up) counts as a start. */
    public function created(BranchManagerShift $shift): void
    {
        if ($shift->status === 'in_progress') {
            event(new ManagerShiftStartedEvent($shift));
        }
    }
}
