<?php

namespace Modules\RecurringOrder\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Purchase\Events\OrderStatusChanged;
use Modules\RecurringOrder\Listeners\UpdateRecurringOrderWhenPurchaseOrderEnded;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        OrderStatusChanged::class => [
            UpdateRecurringOrderWhenPurchaseOrderEnded::class,
        ],
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
