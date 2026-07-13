<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Services\ShiftFeedbackBridgeService;

/**
 * Reverse leg of the shift bridge (WS1a): a final-approved / rejected shifts
 * operation mirrors its decision back onto the legacy cashier_shifts row.
 * Sibling to ProcessShiftOperationDecision (which drives the asab side), keeping
 * legacy-world knowledge out of ShiftCloseService (SRP).
 */
class BridgeShiftDecisionToLegacy
{
    public function __construct(private readonly ShiftFeedbackBridgeService $bridge) {}

    public function handleFinalApproved(OperationFinalApproved $event): void
    {
        if ($event->operation->module_key === 'shifts') {
            $this->bridge->syncDecision($event->operation, 'approved');
        }
    }

    public function handleRejected(OperationRejected $event): void
    {
        if ($event->operation->module_key === 'shifts') {
            $this->bridge->syncDecision($event->operation, 'rejected', $event->reason);
        }
    }
}
