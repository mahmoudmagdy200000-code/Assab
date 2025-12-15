<?php

namespace Modules\Custody\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\CashierShiftHandover;

class HandoverApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CashierShiftHandover $handover
    ) {}
}
