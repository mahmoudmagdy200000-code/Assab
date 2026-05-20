<?php

namespace Modules\Cashier\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Cashier\Events\CashierActivatedEvent;
use Modules\Cashier\Events\CashierCreatedEvent;
use Modules\Cashier\Events\CashierDeactivatedEvent;
use Modules\Cashier\Listeners\HandleCashierDeactivationListener;
use Modules\Cashier\Listeners\LogCashierActivationListener;
use Modules\Cashier\Listeners\SendActivationNotificationListener;

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
