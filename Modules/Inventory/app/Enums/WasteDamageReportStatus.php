<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportStatus: string
{
    case DRAFT = 'draft';
    /** Assigned to staff and not yet confirmed by Branch Manager (staff submission state is exposed via `isStaffInventored`) */
    case PENDING = 'pending';
    /**
     * Legacy state: rows submitted while the 2026-02-25 split was live keep this
     * value in the DB (the 2026-05-26 manager_confirmed_at migration never
     * converted them back). The case must exist or the enum cast throws on read.
     */
    case PENDING_YOUR_CONFIRMATION = 'pending_your_confirmation';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::PENDING_YOUR_CONFIRMATION => 'Pending Your Confirmation',
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
