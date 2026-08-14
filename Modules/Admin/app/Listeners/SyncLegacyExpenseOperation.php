<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseRejectedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;

/**
 * SRS MOB-1.1 / §13 — mirror a mobile-app expense into the ASAB accountant
 * inbox the moment the branch manager submits it, and again whenever the brand
 * owner decides it: that decision CLOSES the mirrored operation, so the
 * accountant and the head of accounts can no longer act on the record
 * (meeting 2026-08-14, approval cycle 1).
 *
 * The bridge no-ops for branches that belong to no ASAB company, so the legacy
 * app keeps working standalone.
 */
class SyncLegacyExpenseOperation
{
    public function __construct(private readonly ExpenseBridgeService $bridge) {}

    public function handle(ExpenseSubmittedEvent|ExpenseApprovedEvent|ExpenseRejectedEvent $event): void
    {
        $this->bridge->sync($event->expense);
    }
}
