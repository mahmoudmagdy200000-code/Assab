<?php

namespace Modules\FixedAssets\Enums;

enum FixedAssetsCondition: string
{
    case EXCELLENT = 'excellent';
    case NEED_ATTENTION = 'need_attention';
    case PROBLEM = 'problem';

    public static function fromAny(?string $value): self
    {
        return match ($value) {
            'excellent' => self::EXCELLENT,
            'need_attention' => self::NEED_ATTENTION,
            'problem' => self::PROBLEM,
            default => self::EXCELLENT,
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
