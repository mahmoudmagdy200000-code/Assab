<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Services\LegacyManagerShiftMirror;
use Modules\Shift\Events\ManagerShiftEndedEvent;

/**
 * The manager ended their workday — finish the live mirror row so they stop
 * showing as «نشط». No pipeline operation is minted here: the manager's money
 * reaches the accountant once, as the daily sales statement
 * (BridgeManagerDailyClose).
 */
class BridgeManagerShiftClose
{
    public function __construct(private readonly LegacyManagerShiftMirror $mirror) {}

    public function handle(ManagerShiftEndedEvent $event): void
    {
        $this->mirror->close($event->managerShift);
    }
}
