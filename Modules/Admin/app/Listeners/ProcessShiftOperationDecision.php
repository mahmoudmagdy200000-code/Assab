<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Services\ShiftCloseService;

/**
 * Bridges the operation pipeline back to the shift world (ACC-6.4 / HEAD-2.5):
 * a final-approved shifts operation closes its shift and posts the cash gap; a
 * rejected one reopens it. Keeps OperationService free of shift knowledge.
 */
class ProcessShiftOperationDecision
{
    public function __construct(private readonly ShiftCloseService $shifts) {}

    public function handleFinalApproved(OperationFinalApproved $event): void
    {
        if ($event->operation->module_key === 'shifts') {
            $this->shifts->onFinalApproved($event->operation, $event->actor);
        }
    }

    public function handleRejected(OperationRejected $event): void
    {
        if ($event->operation->module_key === 'shifts') {
            $this->shifts->onRejected($event->operation, $event->reason);
        }
    }
}
