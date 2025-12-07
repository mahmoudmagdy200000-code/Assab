<?php

namespace Modules\Purchase\Enums;

enum SupplierStatus: string
{
    case ONLINE = 'online';
    case OFFLINE = 'offline';
    case AWAY = 'away';

    public function label(): string
    {
        return match($this) {
            self::ONLINE => 'Online',
            self::OFFLINE => 'Offline',
            self::AWAY => 'Away',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::ONLINE => '#22C55E',
            self::OFFLINE => '#EF4444',
            self::AWAY => '#F59E0B',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::ONLINE;
    }
}

