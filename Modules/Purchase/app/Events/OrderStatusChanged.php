<?php

namespace Modules\Purchase\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;

class OrderStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PurchaseOrder $order,
        public OrderStatus $oldStatus,
        public OrderStatus $newStatus,
        public ?string $reason = null
    ) {}
}
