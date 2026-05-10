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
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
