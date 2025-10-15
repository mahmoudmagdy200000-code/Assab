<?php

namespace Modules\Shift\Events;

use Modules\Shift\Models\CashierShift;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShiftEndedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CashierShift $shift,
        public bool $hasHandover
    ) {}
}
