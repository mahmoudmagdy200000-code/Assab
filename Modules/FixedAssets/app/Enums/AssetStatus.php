<?php

namespace Modules\FixedAssets\Enums;

enum AssetStatus: string
{
    case EXCELLENT = 'excellent';
    case NEED_ATTENTION = 'need_attention';
    case PROBLEM = 'problem';

    public function label(): string
    {
        return match ($this) {
            self::EXCELLENT => 'Excellent',
            self::NEED_ATTENTION => 'Need Attention',
            self::PROBLEM => 'Problem',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
