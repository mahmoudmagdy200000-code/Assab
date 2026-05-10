<?php

namespace Modules\FixedAssets\Enums;

enum DisposalMethod: string
{
    case SCRAP = 'scrap';
    case SELL = 'sell';
    case RETURN_TO_SUPPLIER = 'return_to_supplier';
    case OTHER = 'other';

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
