<?php

namespace Modules\Notification\Enums;

enum DevicePlatform: string
{
    case ANDROID = 'android';
    case IOS = 'ios';
    case WEB = 'web';

    public function label(): string
    {
        return match ($this) {
            self::ANDROID => 'Android',
            self::IOS => 'iOS',
            self::WEB => 'Web',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
