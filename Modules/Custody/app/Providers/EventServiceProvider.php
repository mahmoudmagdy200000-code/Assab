<?php

namespace Modules\Custody\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        \Modules\Shift\Events\VarianceRecorded::class => [
            \Modules\Custody\Listeners\CreateCustodyLedgerEntriesForVariance::class,
        ],
        \Modules\Expense\Events\ExpenseApprovedEvent::class => [
            \Modules\Custody\Listeners\CreateCustodyTransactionFromExpenseApproval::class,
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
