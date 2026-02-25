<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportStatus: string
{
    case DRAFT = 'draft';
    /** When staff submitted — pending manager confirmation */
    case PENDING = 'pending';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
        };
    }

    /** Badge label for list UI */
    public function listLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending your confirmation',
            self::COMPLETED => 'Completed',
        };
    }

    /** Status text for detail "Report Submitted" card */
    public function detailStatusLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending your confirmation',
            self::COMPLETED => 'Completed',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    /** When staff has submitted, awaiting confirmation */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /** Alias: submitted = pending (staff submitted) */
    public function isSubmitted(): bool
    {
        return $this->isPending();
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }
}
