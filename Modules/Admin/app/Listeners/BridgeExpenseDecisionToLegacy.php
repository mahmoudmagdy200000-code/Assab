<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Events\OperationApproved;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Events\OperationReturnedForReview;
use Modules\Admin\Services\ExpenseFeedbackBridgeService;

/**
 * Reverse leg of the expense bridge (WS1b): every dashboard decision on an
 * expenses operation writes its outcome back onto the legacy expenses row.
 * Sibling to SyncLegacyExpenseOperation (the forward, read leg).
 *
 * All four stages of the accountant cycle are mirrored, not just the terminal
 * two: the mobile app has to show «موافق عليه من المحاسب» while the record is
 * still waiting on the head of accounts (meeting 2026-08-14).
 */
class BridgeExpenseDecisionToLegacy
{
    public function __construct(private readonly ExpenseFeedbackBridgeService $bridge) {}

    public function handleApproved(OperationApproved $event): void
    {
        $this->forward($event->operation, $event->actor);
    }

    public function handleFinalApproved(OperationFinalApproved $event): void
    {
        $this->forward($event->operation, $event->actor);
    }

    public function handleRejected(OperationRejected $event): void
    {
        $this->forward($event->operation, $event->actor);
    }

    public function handleReturnedForReview(OperationReturnedForReview $event): void
    {
        $this->forward($event->operation, $event->actor);
    }

    private function forward(\Modules\Admin\Models\Operation $operation, ?\Modules\Admin\Models\AsabUser $actor = null): void
    {
        if ($operation->module_key === 'expenses') {
            $this->bridge->syncFromOperation($operation, $actor);
        }
    }
}
