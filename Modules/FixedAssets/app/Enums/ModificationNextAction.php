<?php

namespace Modules\FixedAssets\Enums;

enum ModificationNextAction: string
{
    case NEED_TECHNICIAN = 'need_technician';
    case HALT = 'halt';
    case REPLACE = 'replace';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
