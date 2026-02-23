<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Submitted',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this === self::SUBMITTED;
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
