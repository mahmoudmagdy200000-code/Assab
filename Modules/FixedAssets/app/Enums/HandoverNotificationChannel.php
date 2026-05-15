<?php

namespace Modules\FixedAssets\Enums;

enum HandoverNotificationChannel: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
