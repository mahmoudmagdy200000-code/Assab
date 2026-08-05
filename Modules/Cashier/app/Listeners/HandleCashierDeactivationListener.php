<?php

namespace Modules\Cashier\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Cashier\Events\CashierDeactivatedEvent;
use Modules\Shift\Enums\ShiftStatus;

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

        // Cancel pending shifts. The literal used to be 'cancelled' — a spelling
        // that exists in neither the PHP enum ('canceled') nor the MySQL column,
        // so every deactivation blew up with «Data truncated for column status».
        $event->cashier->shifts()
            ->where('status', ShiftStatus::NOT_STARTED->value)
            ->whereDate('shift_date', '>=', today())
            ->update(['status' => ShiftStatus::CANCELED->value]);
    }
}
