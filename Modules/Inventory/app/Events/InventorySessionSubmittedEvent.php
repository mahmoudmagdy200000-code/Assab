<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\InventorySession;

/**
 * A daily quick inventory session was submitted (or approved — re-fired so the
 * discrepancy figures re-sync). The ASAB bridge turns it into a
 * `module_key='inventory'` operation for the accountant.
 */
class InventorySessionSubmittedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public InventorySession $session
    ) {}
}
