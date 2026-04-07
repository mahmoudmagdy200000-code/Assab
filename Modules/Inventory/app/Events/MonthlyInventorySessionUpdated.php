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
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $inventoryId,
        public readonly string $eventType,
        public readonly array $payload
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        $suffix = match ($this->eventType) {
            'product.claimed', 'product.released' => '.pending',
            'product.updated' => '.completed',
            default => '',
        };

        return new PresenceChannel('inventory.monthly.' . $this->inventoryId . $suffix);
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
