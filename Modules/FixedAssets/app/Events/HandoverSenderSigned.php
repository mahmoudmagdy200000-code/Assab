<?php

namespace Modules\FixedAssets\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Models\HandoverSignature;

class HandoverSenderSigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Handover $handover,
        public HandoverSignature $signature,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('handover-session.'.$this->handover->id);
    }

    public function broadcastAs(): string
    {
        return 'handover.signature.sender';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => (string) $this->handover->id,
            'sender' => [
                'name' => (string) $this->signature->signed_by_name_snapshot,
                'isSigned' => true,
                'signedAt' => $this->signature->signed_at?->toIso8601String() ?? '',
            ],
        ];
    }
}
