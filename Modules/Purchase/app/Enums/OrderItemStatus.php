<?php

namespace Modules\Purchase\Enums;

enum OrderItemStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case PARTIAL = 'partial';
    case REJECTED = 'rejected';
    case RECEIVED = 'received';
    case VARIANCE = 'variance';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::CONFIRMED => 'Confirmed',
            self::PARTIAL => 'Partial',
            self::REJECTED => 'Rejected',
            self::RECEIVED => 'Received',
            self::VARIANCE => 'Variance',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#F59E0B',
            self::CONFIRMED => '#10B981',
            self::PARTIAL => '#8B5CF6',
            self::REJECTED => '#EF4444',
            self::RECEIVED => '#22C55E',
            self::VARIANCE => '#F97316',
        };
    }

    public function isAccepted(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::PARTIAL,
            self::RECEIVED,
        ]);
    }

    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }
}

