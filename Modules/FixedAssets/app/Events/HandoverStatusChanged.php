<?php

namespace Modules\FixedAssets\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\FixedAssets\Models\Handover;

class HandoverStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Handover $handover) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('handover-session.'.$this->handover->id);
    }

    public function broadcastAs(): string
    {
        return 'handover.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'handoverId' => (string) $this->handover->id,
            'sessionId' => (string) $this->handover->id,
            'status' => $this->handover->status?->value,
        ];
    }
}
