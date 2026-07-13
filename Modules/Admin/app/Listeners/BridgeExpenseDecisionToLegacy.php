<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Services\ExpenseFeedbackBridgeService;

/**
 * Reverse leg of the expense bridge (WS1b): a final-approved / rejected expenses
 * operation writes its outcome back onto the legacy expenses row. Sibling to
 * SyncLegacyExpenseOperation (the forward, read leg).
 */
class BridgeExpenseDecisionToLegacy
{
    public function __construct(private readonly ExpenseFeedbackBridgeService $bridge) {}

    public function handleFinalApproved(OperationFinalApproved $event): void
    {
        if ($event->operation->module_key === 'expenses') {
            $this->bridge->syncFromOperation($event->operation);
        }
    }

    public function handleRejected(OperationRejected $event): void
    {
        if ($event->operation->module_key === 'expenses') {
            $this->bridge->syncFromOperation($event->operation);
        }
    }
}
