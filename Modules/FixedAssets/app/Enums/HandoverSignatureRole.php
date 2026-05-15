<?php

namespace Modules\FixedAssets\Enums;

enum HandoverSignatureRole: string
{
    case SENDER = 'sender';
    case RECEIVER = 'receiver';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
