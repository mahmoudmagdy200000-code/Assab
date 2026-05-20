<?php

namespace Modules\Purchase\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchase\Models\PurchaseOrder;

class OrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PurchaseOrder $order
    ) {}
}
