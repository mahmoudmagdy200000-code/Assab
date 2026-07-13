<?php

namespace Modules\Admin\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Listeners\BridgeExpenseDecisionToLegacy;
use Modules\Admin\Listeners\BridgeLegacyCashierShift;
use Modules\Admin\Listeners\BridgeShiftDecisionToLegacy;
use Modules\Admin\Listeners\ProcessShiftOperationDecision;
use Modules\Admin\Listeners\SyncErpReadyBatch;
use Modules\Admin\Listeners\SyncLegacyExpenseOperation;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Shift\Events\ShiftEndedEvent;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // Two-worlds bridge (SRS §13 / MOB-1.1): a mobile-app expense becomes an
        // asab_operations row so the dashboard accountant can review it.
        ExpenseSubmittedEvent::class => [SyncLegacyExpenseOperation::class],
        ExpenseApprovedEvent::class => [SyncLegacyExpenseOperation::class],

        // Shift close chain (ACC-6.4 / HEAD-2.5): final-approve closes the shift +
        // posts the cash gap; reject reopens it. The two Bridge*DecisionToLegacy
        // listeners (WS1a/WS1b) mirror the terminal decision back to the mobile
        // world (cashier_shifts.review_status / expenses.status).
        OperationFinalApproved::class => [
            [ProcessShiftOperationDecision::class, 'handleFinalApproved'],
            [BridgeShiftDecisionToLegacy::class, 'handleFinalApproved'],
            [BridgeExpenseDecisionToLegacy::class, 'handleFinalApproved'],
            // SRS §14.3 ERP-1: seed the (day × module) ready batch for export.
            SyncErpReadyBatch::class,
        ],
        OperationRejected::class => [
            [ProcessShiftOperationDecision::class, 'handleRejected'],
            [BridgeShiftDecisionToLegacy::class, 'handleRejected'],
            [BridgeExpenseDecisionToLegacy::class, 'handleRejected'],
        ],

        // MOB-1.6 cashier bridge: a legacy mobile shift close mints the SHF- op.
        ShiftEndedEvent::class => [BridgeLegacyCashierShift::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
