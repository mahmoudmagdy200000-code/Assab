<?php

namespace Modules\FixedAssets\Enums;

enum SearchType: string
{
    case GENERAL = 'general';
    case QUICK = 'quick';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
