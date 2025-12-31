<?php

namespace Modules\Inventory\Enums;

enum InventorySessionStatus: string
{
    case DRAFT = 'draft';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::COMPLETED => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => '#F59E0B',
            self::COMPLETED => '#10B981',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }
}

