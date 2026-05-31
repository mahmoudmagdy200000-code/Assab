<?php

namespace Modules\Admin\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Single parametrized broadcast event covering every ASAB real-time signal
 * (BACKEND_API_SPEC.md §8). The channel base names match the spec exactly
 * (notifications.user.{id} | operations.brand.{id} | reminders.branch.{id});
 * Echo/pusher-js prefixes the transport `private-` on the wire. broadcastAs()
 * returns the spec event name (notification.new, operation.status_changed, …).
 */
class AsabRealtimeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $channelName  spec channel (without transport prefix)
     * @param  string  $eventName  spec server-sent event name
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $channelName,
        public string $eventName,
        public array $payload,
    ) {}

    /** @return PrivateChannel[] */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channelName)];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
