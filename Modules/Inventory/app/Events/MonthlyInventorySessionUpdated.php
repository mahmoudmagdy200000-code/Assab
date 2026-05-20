<?php

namespace Modules\Inventory\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MonthlyInventorySessionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $inventoryId,
        public readonly string $eventType,
        public readonly array $payload
    ) {}

    /**
     * Broadcast ALL events on the main channel.
     * Frontend can filter by event_type in the payload (product.claimed, product.released, product.updated, etc.).
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('inventory.monthly.'.$this->inventoryId);
    }

    public function broadcastAs(): string
    {
        return 'inventory.monthly.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'inventory_id' => $this->inventoryId,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
