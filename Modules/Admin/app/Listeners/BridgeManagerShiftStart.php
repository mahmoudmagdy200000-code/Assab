<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Services\LegacyManagerShiftMirror;
use Modules\Shift\Events\ManagerShiftStartedEvent;

/**
 * A manager's mobile «بدء الشيفت» opens the live mirror row the dashboard board
 * reads. Best-effort by design: a mirror that cannot be written (unlinked
 * branch) is logged by the mirror service and must never fail the manager's
 * start request.
 */
class BridgeManagerShiftStart
{
    public function __construct(private readonly LegacyManagerShiftMirror $mirror) {}

    public function handle(ManagerShiftStartedEvent $event): void
    {
        $this->mirror->open($event->managerShift);
    }
}
