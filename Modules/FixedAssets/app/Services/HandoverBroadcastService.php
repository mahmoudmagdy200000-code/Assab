<?php

namespace Modules\FixedAssets\Services;

use Modules\FixedAssets\Events\HandoverReceiverSigned;
use Modules\FixedAssets\Events\HandoverSenderSigned;
use Modules\FixedAssets\Events\HandoverStatusChanged;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Models\HandoverSignature;

class HandoverBroadcastService
{
    public function statusChanged(Handover $handover): void
    {
        broadcast(new HandoverStatusChanged($handover))->toOthers();
    }

    public function receiverSigned(Handover $handover, HandoverSignature $signature): void
    {
        broadcast(new HandoverReceiverSigned($handover, $signature))->toOthers();
    }

    public function senderSigned(Handover $handover, HandoverSignature $signature): void
    {
        broadcast(new HandoverSenderSigned($handover, $signature))->toOthers();
    }
}
