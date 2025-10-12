<?php

namespace Modules\Cashier\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Cashier\Events\CashierActivatedEvent;

class LogCashierActivationListener
{
    public function handle(CashierActivatedEvent $event): void
    {
        Log::info('Cashier activated', [
            'cashier_id' => $event->cashier->id,
            'email' => $event->cashier->email,
            'activated_at' => $event->cashier->activated_at,
        ]);

        // Additional actions like sending welcome email
        // $event->cashier->notify(new WelcomeNotification());
    }
}
