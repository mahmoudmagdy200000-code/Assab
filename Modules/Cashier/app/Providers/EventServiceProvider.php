<?php

namespace Modules\Cashier\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Cashier\Events\{
    CashierCreatedEvent,
    CashierActivatedEvent,
    CashierDeactivatedEvent
};
use Modules\Cashier\Listeners\{
    SendActivationNotificationListener,
    LogCashierActivationListener,
    HandleCashierDeactivationListener
};

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CashierCreatedEvent::class => [
            SendActivationNotificationListener::class,
        ],
        CashierActivatedEvent::class => [
            LogCashierActivationListener::class,
        ],
        CashierDeactivatedEvent::class => [
            HandleCashierDeactivationListener::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
