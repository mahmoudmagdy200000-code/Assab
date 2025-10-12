<?php

namespace Modules\Cashier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Cashier\Models\Cashier;

class CashierDeactivatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cashier $cashier
    ) {}
}
