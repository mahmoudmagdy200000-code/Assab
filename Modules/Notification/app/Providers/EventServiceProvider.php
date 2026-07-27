<?php

namespace Modules\Notification\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Notification\Listeners\AssetHandoverNotificationListener;
use Modules\Notification\Listeners\BranchManagerAccountNotificationListener;
use Modules\Notification\Listeners\CashierAccountNotificationListener;
use Modules\Notification\Listeners\CustodyNotificationListener;
use Modules\Notification\Listeners\ExpenseNotificationListener;
use Modules\Notification\Listeners\GoodsReceivedNotificationListener;
use Modules\Notification\Listeners\InventorySessionNotificationListener;
use Modules\Notification\Listeners\OperationDecisionNotificationListener;
use Modules\Notification\Listeners\OrderStatusChangedListener;
use Modules\Notification\Listeners\PurchaseNotificationListener;
use Modules\Notification\Listeners\ReturnOrderNotificationListener;
use Modules\Notification\Listeners\ShiftNotificationListener;
use Modules\Notification\Listeners\ShiftVarianceNotificationListener;
use Modules\Notification\Listeners\VarianceDetectedListener;

/**
 * Cross-module notification wiring.
 *
 * Every domain event that a human needs to react to lands here. Listeners are
 * queued, so a slow FCM round-trip never delays the request that raised the
 * event.
 */
class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // ── Shift ───────────────────────────────────────────────────────────
        \Modules\Shift\Events\ShiftEndedEvent::class => [
            ShiftNotificationListener::class,
        ],
        \Modules\Shift\Events\VarianceRecorded::class => [
            ShiftVarianceNotificationListener::class,
        ],

        // ── Expense ─────────────────────────────────────────────────────────
        \Modules\Expense\Events\ExpenseSubmittedEvent::class => [
            ExpenseNotificationListener::class,
        ],
        \Modules\Expense\Events\ExpenseApprovedEvent::class => [
            \Modules\Notification\Listeners\ExpenseApprovedListener::class,
        ],
        \Modules\Expense\Events\ExpenseRejectedEvent::class => [
            \Modules\Notification\Listeners\ExpenseRejectedListener::class,
        ],

        // ── Custody ─────────────────────────────────────────────────────────
        \Modules\Custody\Events\HandoverApproved::class => [
            CustodyNotificationListener::class,
        ],

        // ── Purchase ────────────────────────────────────────────────────────
        \Modules\Purchase\Events\OrderCreated::class => [
            PurchaseNotificationListener::class,
        ],
        \Modules\Purchase\Events\OrderStatusChanged::class => [
            OrderStatusChangedListener::class,
        ],
        \Modules\Purchase\Events\VarianceDetected::class => [
            VarianceDetectedListener::class,
        ],
        \Modules\Purchase\Events\GoodsReceived::class => [
            GoodsReceivedNotificationListener::class,
        ],
        \Modules\Purchase\Events\ReturnOrderSubmitted::class => [
            ReturnOrderNotificationListener::class,
        ],
        \Modules\Purchase\Events\ReturnOrderApproved::class => [
            ReturnOrderNotificationListener::class,
        ],

        // ── Cashier account lifecycle ───────────────────────────────────────
        \Modules\Cashier\Events\CashierCreatedEvent::class => [
            CashierAccountNotificationListener::class,
        ],
        \Modules\Cashier\Events\CashierActivatedEvent::class => [
            CashierAccountNotificationListener::class,
        ],
        \Modules\Cashier\Events\CashierDeactivatedEvent::class => [
            CashierAccountNotificationListener::class,
        ],

        // ── Branch manager account lifecycle ────────────────────────────────
        \Modules\BranchManagers\Events\BranchManagerCreatedEvent::class => [
            BranchManagerAccountNotificationListener::class,
        ],
        \Modules\BranchManagers\Events\BranchManagerSuspendedEvent::class => [
            BranchManagerAccountNotificationListener::class,
        ],

        // ── ASAB dashboard approval chain ───────────────────────────────────
        \Modules\Admin\Events\OperationFinalApproved::class => [
            OperationDecisionNotificationListener::class,
        ],
        \Modules\Admin\Events\OperationRejected::class => [
            OperationDecisionNotificationListener::class,
        ],

        // ── Fixed assets ────────────────────────────────────────────────────
        \Modules\FixedAssets\Events\HandoverStarted::class => [
            AssetHandoverNotificationListener::class,
        ],
        \Modules\FixedAssets\Events\HandoverSenderSigned::class => [
            AssetHandoverNotificationListener::class,
        ],
        \Modules\FixedAssets\Events\HandoverReceiverSigned::class => [
            AssetHandoverNotificationListener::class,
        ],
        \Modules\FixedAssets\Events\HandoverStatusChanged::class => [
            AssetHandoverNotificationListener::class,
        ],

        // ── Inventory ───────────────────────────────────────────────────────
        \Modules\Inventory\Events\MonthlyInventorySessionUpdated::class => [
            InventorySessionNotificationListener::class,
        ],
    ];
}
