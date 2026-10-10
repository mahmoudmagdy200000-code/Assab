<?php

namespace Modules\Shift\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\CashierShift;

class ShiftEndedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CashierShift $shift,
        public bool $hasHandover
    ) {}
}
