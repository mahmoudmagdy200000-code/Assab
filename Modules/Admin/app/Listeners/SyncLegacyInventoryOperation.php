<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Services\InventoryBridgeService;
use Modules\Inventory\Events\InventorySessionSubmittedEvent;

/**
 * Forward leg of the inventory bridge (meeting 2026-07-30): a submitted mobile
 * daily inventory surfaces as an INV- operation in the accountant's inbox;
 * approval re-fires the event so discrepancy figures re-sync.
 */
class SyncLegacyInventoryOperation
{
    public function __construct(private readonly InventoryBridgeService $bridge) {}

    public function handle(InventorySessionSubmittedEvent $event): void
    {
        $this->bridge->sync($event->session);
    }
}
