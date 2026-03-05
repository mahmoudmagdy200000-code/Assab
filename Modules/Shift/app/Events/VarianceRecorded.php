<?php

namespace Modules\Shift\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\CashierShift;

class VarianceRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CashierShift $shift
    ) {}
}
