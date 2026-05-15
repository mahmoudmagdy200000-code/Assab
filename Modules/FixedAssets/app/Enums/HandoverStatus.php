<?php

namespace Modules\FixedAssets\Enums;

enum HandoverStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case PENDING = 'pending';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'Pending Approval',
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
