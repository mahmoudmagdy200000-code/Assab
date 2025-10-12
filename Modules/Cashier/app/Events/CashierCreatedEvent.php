<?php

namespace Modules\Cashier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Cashier\Models\Cashier;

class CashierCreatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cashier $cashier,
        public string $defaultPassword
    ) {}
}
