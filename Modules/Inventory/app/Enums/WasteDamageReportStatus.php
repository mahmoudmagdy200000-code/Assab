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

    /** Badge label for list UI: Draft | Pending */
    public function listLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Pending',
        };
    }

    /** Status text for detail "Report Submitted" card */
    public function detailStatusLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Pending Review',
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
