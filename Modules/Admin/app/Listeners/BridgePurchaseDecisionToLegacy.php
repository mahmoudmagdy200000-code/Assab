<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Services\PurchaseFeedbackBridgeService;

/**
 * Reverse leg of the purchase bridge: a final-approved / rejected purchases
 * operation writes its outcome back onto the legacy purchase_orders row.
 * Sibling to BridgeLegacyPurchaseOrder (the forward leg).
 */
class BridgePurchaseDecisionToLegacy
{
    public function __construct(private readonly PurchaseFeedbackBridgeService $bridge) {}

    public function handleFinalApproved(OperationFinalApproved $event): void
    {
        if ($event->operation->module_key === 'purchases') {
            $this->bridge->syncFromOperation($event->operation);
        }
    }

    public function handleRejected(OperationRejected $event): void
    {
        if ($event->operation->module_key === 'purchases') {
            $this->bridge->syncFromOperation($event->operation);
        }
    }
}
