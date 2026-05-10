<?php

namespace Modules\FixedAssets\Enums;

enum ModificationDoneAction: string
{
    case INITIAL_CHECK = 'initial_check';
    case CLEANING = 'cleaning';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
