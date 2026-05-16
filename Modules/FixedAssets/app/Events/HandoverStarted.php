<?php

namespace Modules\FixedAssets\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\FixedAssets\Models\Handover;

class HandoverStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Handover $handover) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('handover-session.'.$this->handover->id);
    }

    public function broadcastAs(): string
    {
        return 'handover.started';
    }

    public function broadcastWith(): array
    {
        return [
            'handoverId' => (string) $this->handover->id,
            'sessionId' => (string) $this->handover->id,
            'sessionCode' => (string) $this->handover->session_code,
            'status' => $this->handover->status?->value,
            'startedAt' => $this->handover->started_at?->toIso8601String() ?? '',
        ];
    }
}
