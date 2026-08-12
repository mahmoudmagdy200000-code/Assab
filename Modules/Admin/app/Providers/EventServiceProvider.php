<?php

namespace Modules\Admin\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Listeners\BridgeExpenseDecisionToLegacy;
use Modules\Admin\Listeners\BridgeLegacyCashierShift;
use Modules\Admin\Listeners\BridgeLegacyShiftStart;
use Modules\Admin\Listeners\BridgeManagerDailyClose;
use Modules\Admin\Listeners\BridgeShiftDecisionToLegacy;
use Modules\Admin\Listeners\MirrorMobileCashierToEmployee;
use Modules\Admin\Listeners\ProcessShiftOperationDecision;
use Modules\Admin\Listeners\SyncBranchManagerCredential;
use Modules\Admin\Listeners\SyncBrandOwnerCredential;
use Modules\Admin\Listeners\SyncErpReadyBatch;
use Modules\Admin\Listeners\SyncLegacyExpenseOperation;
use Modules\Admin\Listeners\SyncSupplierCredential;
use Modules\BranchManagers\Events\PasswordChangedEvent as BranchManagerPasswordChanged;
use Modules\BrandOwner\Events\BrandOwnerPasswordChanged;
use Modules\Cashier\Events\CashierCreatedEvent;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Events\ShiftStartedEvent;
use Modules\Supplier\Events\SupplierPasswordChanged;

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
            [\Modules\Admin\Listeners\BridgePurchaseDecisionToLegacy::class, 'handleFinalApproved'],
            // SRS §14.3 ERP-1: seed the (day × module) ready batch for export.
            SyncErpReadyBatch::class,
        ],
        OperationRejected::class => [
            [ProcessShiftOperationDecision::class, 'handleRejected'],
            [BridgeShiftDecisionToLegacy::class, 'handleRejected'],
            [BridgeExpenseDecisionToLegacy::class, 'handleRejected'],
            [\Modules\Admin\Listeners\BridgePurchaseDecisionToLegacy::class, 'handleRejected'],
        ],

        // MOB-1.6 cashier bridge: a mobile shift START opens the asab_shifts row
        // the accountant's live board reads, and the CLOSE finishes that same row
        // (minting the SHF- pipeline operation).
        ShiftStartedEvent::class => [BridgeLegacyShiftStart::class],
        ShiftEndedEvent::class => [BridgeLegacyCashierShift::class],

        // Same board, manager half: a branch manager running the branch is a
        // `role='branch_manager'` mirror row, opened at «بدء الشيفت» and closed
        // at end of workday. Display-only — no SHF- operation (the manager's
        // money reaches the accountant once, via the daily sales statement).
        \Modules\Shift\Events\ManagerShiftStartedEvent::class => [\Modules\Admin\Listeners\BridgeManagerShiftStart::class],
        \Modules\Shift\Events\ManagerShiftEndedEvent::class => [\Modules\Admin\Listeners\BridgeManagerShiftClose::class],

        // FR-SAL: the manager's mobile daily close becomes the branch's daily
        // sales statement (sales operation) in the accountant's المبيعات inbox.
        \Modules\Shift\Events\DailyReportSubmittedEvent::class => [BridgeManagerDailyClose::class],

        // Meeting 2026-07-30: a submitted mobile daily inventory becomes an
        // INV- operation for the accountant (re-synced on approval).
        \Modules\Inventory\Events\InventorySessionSubmittedEvent::class => [\Modules\Admin\Listeners\SyncLegacyInventoryOperation::class],

        // Meeting 2026-07-30: assigning a dashboard asset to a branch creates
        // the mobile receive request + pushes the branch manager.
        \Modules\Admin\Events\AssetAssignedToBranch::class => [\Modules\Admin\Listeners\BridgeAssetToBranchReceipt::class],

        // Meeting 2026-07-30: a mobile purchase order becomes a PUR- operation
        // in the accountant's inbox; later status changes refresh rcvQty.
        \Modules\Purchase\Events\OrderCreated::class => [
            [\Modules\Admin\Listeners\BridgeLegacyPurchaseOrder::class, 'handleCreated'],
        ],
        \Modules\Purchase\Events\OrderStatusChanged::class => [
            [\Modules\Admin\Listeners\BridgeLegacyPurchaseOrder::class, 'handleStatusChanged'],
        ],

        // Cashiers are added in the mobile app by the branch manager (the
        // dashboard no longer creates them), so mirror each one into
        // asab_employees — the dashboard branch roster and the shift bridge
        // both resolve the cashier through that row.
        CashierCreatedEvent::class => [MirrorMobileCashierToEmployee::class],

        // Credential bridge: a password set in the mobile app is copied onto the
        // linked asab_users row so one password opens both worlds.
        BrandOwnerPasswordChanged::class => [SyncBrandOwnerCredential::class],
        SupplierPasswordChanged::class => [SyncSupplierCredential::class],
        BranchManagerPasswordChanged::class => [SyncBranchManagerCredential::class],
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
