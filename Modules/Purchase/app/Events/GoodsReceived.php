<?php

namespace Modules\Purchase\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchase\Models\GoodsReceipt;

class GoodsReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GoodsReceipt $receipt,
        public bool $hasVariances = false
    ) {}
}

