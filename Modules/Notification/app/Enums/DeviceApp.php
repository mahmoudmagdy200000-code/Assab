<?php

namespace Modules\Notification\Enums;

/**
 * Which client registered the token. The two worlds ship separate Firebase
 * apps, so the same human may hold one token per app and both must be reachable.
 */
enum DeviceApp: string
{
    case MOBILE = 'mobile';
    case DASHBOARD = 'dashboard';

    public function label(): string
    {
        return match ($this) {
            self::MOBILE => 'Mobile App',
            self::DASHBOARD => 'Dashboard',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
