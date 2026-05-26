<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportStatus: string
{
    case DRAFT = 'draft';
    /** Assigned to staff and not yet confirmed by Branch Manager (staff submission state is exposed via `isStaffInventored`) */
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::COMPLETED => 'Completed',
        };
    }

    /** Badge label for list UI */
    public function listLabel(): string
    {
        return $this->label();
    }

    /** Status text for detail "Report Submitted" card */
    public function detailStatusLabel(): string
    {
        return $this->label();
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /** Approved is treated as Completed (kept only for legacy/testing rows). */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED || $this === self::APPROVED;
    }

    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::COMPLETED], true);
    }

    /** Editable by assignee: draft (manager) or pending (staff still working) */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::PENDING;
    }
}
