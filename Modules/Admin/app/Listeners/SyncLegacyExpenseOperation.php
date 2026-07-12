<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;

/**
 * SRS MOB-1.1 / §13 — mirror a mobile-app expense into the ASAB accountant
 * inbox the moment the branch manager submits it (and again on brand-owner
 * approval, in case invoices were edited in between).
 *
 * The bridge no-ops for branches that belong to no ASAB company, so the legacy
 * app keeps working standalone.
 */
class SyncLegacyExpenseOperation
{
    public function __construct(private readonly ExpenseBridgeService $bridge) {}

    public function handle(ExpenseSubmittedEvent|ExpenseApprovedEvent $event): void
    {
        $this->bridge->sync($event->expense);
    }
}
