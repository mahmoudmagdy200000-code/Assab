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
        // BRC v3.4 F-01: there is intentionally NO expense listener here. The old queued listener charged the
        // custody at APPROVAL (and spilled any shortfall into the manager's sales-cash ledger). An expense's
        // custody movements are now written only by Modules\Custody\Services\ExpenseCustodyService, inside the
        // submit / approve / reject / resubmit transaction (EXP-02…EXP-08).
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
