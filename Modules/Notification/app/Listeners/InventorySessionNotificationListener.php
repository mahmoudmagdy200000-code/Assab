<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Inventory\Events\MonthlyInventorySessionUpdated;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;

/**
 * Monthly inventory sessions → push.
 *
 * The event fires on every progress save so the presence channel can update
 * live counters. Only the terminal `inventory.submitted` transition is worth a
 * push — pushing progress saves would be one notification per counted product.
 */
class InventorySessionNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    private const NOTIFIABLE_EVENT_TYPES = ['inventory.submitted'];

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(MonthlyInventorySessionUpdated $event): void
    {
        if (! in_array($event->eventType, self::NOTIFIABLE_EVENT_TYPES, true)) {
            return;
        }

        $inventory = MonthlyInventory::query()
            ->select(['id', 'inventory_number', 'branch_id', 'status'])
            ->find($event->inventoryId);

        if ($inventory === null || ! $inventory->branch_id) {
            return;
        }

        $this->notificationService->sendToRole(
            'branch_manager',
            NotificationType::INVENTORY_SESSION_UPDATED,
            [
                'inventory_id' => $inventory->id,
                'inventory_number' => $inventory->inventory_number,
                'branch_id' => $inventory->branch_id,
                'event_type' => $event->eventType,
            ],
            null,
            $inventory->branch_id
        );
    }
}
