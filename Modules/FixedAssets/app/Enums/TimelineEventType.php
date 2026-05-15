<?php

namespace Modules\FixedAssets\Enums;

enum TimelineEventType: string
{
    case SUBMITTED = 'submitted';
    case REVIEWED = 'reviewed';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RECEIVED = 'received';
    case TRANSFERRED = 'transferred';
    case DISPOSED = 'disposed';
    case CREATED = 'created';
    case UPDATED = 'updated';
    case HANDOVER_STARTED = 'handover_started';
    case HANDOVER_JOINED = 'handover_joined';
    case HANDOVER_ZONE_APPROVED = 'handover_zone_approved';
    case HANDOVER_RECEIVER_SIGNED = 'handover_receiver_signed';
    case HANDOVER_SENDER_SIGNED = 'handover_sender_signed';
    case HANDOVER_COMPLETED = 'handover_completed';

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Submitted',
            self::REVIEWED => 'Reviewed',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::RECEIVED => 'Received',
            self::TRANSFERRED => 'Transferred',
            self::DISPOSED => 'Disposed',
            self::CREATED => 'Created',
            self::UPDATED => 'Updated',
            self::HANDOVER_STARTED => 'Handover started',
            self::HANDOVER_JOINED => 'Handover joined',
            self::HANDOVER_ZONE_APPROVED => 'Zone approved',
            self::HANDOVER_RECEIVER_SIGNED => 'Receiver signed',
            self::HANDOVER_SENDER_SIGNED => 'Sender signed',
            self::HANDOVER_COMPLETED => 'Handover completed',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
