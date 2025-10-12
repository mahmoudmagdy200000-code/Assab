<?php

namespace Modules\Cashier\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Cashier\Events\CashierDeactivatedEvent;

class HandleCashierDeactivationListener
{
    public function handle(CashierDeactivatedEvent $event): void
    {
        Log::info('Cashier deactivated', [
            'cashier_id' => $event->cashier->id,
            'email' => $event->cashier->email,
            'deactivated_at' => $event->cashier->deactivated_at,
        ]);

        // Revoke all tokens
        $event->cashier->tokens()->delete();

        // Cancel pending shifts
        $event->cashier->shifts()
            ->where('status', 'not_started')
            ->whereDate('shift_date', '>=', today())
            ->update(['status' => 'cancelled']);
    }
}
