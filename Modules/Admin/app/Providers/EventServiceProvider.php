<?php

namespace Modules\Admin\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Admin\Listeners\SyncLegacyExpenseOperation;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;

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
