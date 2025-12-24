<?php

namespace Modules\Notification\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Notification\Listeners\ShiftNotificationListener;
use Modules\Notification\Listeners\ExpenseNotificationListener;
use Modules\Notification\Listeners\ExpenseApprovedListener;
use Modules\Notification\Listeners\ExpenseRejectedListener;
use Modules\Notification\Listeners\CustodyNotificationListener;
use Modules\Notification\Listeners\PurchaseNotificationListener;
use Modules\Notification\Listeners\OrderStatusChangedListener;
use Modules\Notification\Listeners\VarianceDetectedListener;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseRejectedEvent;
use Modules\Custody\Events\HandoverApproved;
use Modules\Purchase\Events\OrderStatusChanged;
use Modules\Purchase\Events\VarianceDetected;
use Modules\Purchase\Events\OrderCreated;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Shift events
        \Modules\Shift\Events\ShiftEndedEvent::class => [
            ShiftNotificationListener::class,
        ],

        // Expense events
        \Modules\Expense\Events\ExpenseSubmittedEvent::class => [
            ExpenseNotificationListener::class,
        ],
        \Modules\Expense\Events\ExpenseApprovedEvent::class => [
            \Modules\Notification\Listeners\ExpenseApprovedListener::class,
        ],
        \Modules\Expense\Events\ExpenseRejectedEvent::class => [
            \Modules\Notification\Listeners\ExpenseRejectedListener::class,
        ],

        // Custody events
        \Modules\Custody\Events\HandoverApproved::class => [
            CustodyNotificationListener::class,
        ],

        // Purchase events
        \Modules\Purchase\Events\OrderCreated::class => [
            PurchaseNotificationListener::class,
        ],
        \Modules\Purchase\Events\OrderStatusChanged::class => [
            OrderStatusChangedListener::class,
        ],
        \Modules\Purchase\Events\VarianceDetected::class => [
            VarianceDetectedListener::class,
        ],
    ];
}

