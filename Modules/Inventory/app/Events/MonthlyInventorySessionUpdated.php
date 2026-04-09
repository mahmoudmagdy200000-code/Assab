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

    /**
     * Broadcast on the main channel AND the specific sub-channel.
     * This ensures clients subscribed to either channel receive the event.
     *
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        $mainChannel = new PresenceChannel('inventory.monthly.' . $this->inventoryId);

        $suffix = match ($this->eventType) {
            'product.claimed', 'product.released' => '.pending',
            'product.updated' => '.completed',
            default => null,
        };

        if ($suffix) {
            return [
                $mainChannel,
                new PresenceChannel('inventory.monthly.' . $this->inventoryId . $suffix),
            ];
        }

        return [$mainChannel];
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
