<?php

namespace Modules\Shift\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Models\CashierShift;

/**
 * A cashier started their shift in the mobile app. Counterpart of
 * ShiftEndedEvent: the dashboard's live shift board mirrors the running shift
 * from here instead of only learning about it at close time.
 */
class ShiftStartedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public CashierShift $shift) {}
}
