<?php

namespace Modules\Purchase\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchase\Models\ReturnOrder;

class ReturnOrderApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ReturnOrder $returnOrder
    ) {}
}
