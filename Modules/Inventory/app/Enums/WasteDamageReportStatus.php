<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReportStatus: string
{
    case DRAFT = 'draft';
    /** Assigned to staff; staff has not submitted yet */
    case PENDING = 'pending';
    /** Staff submitted; waiting manager approval */
    case PENDING_YOUR_CONFIRMATION = 'pending_your_confirmation';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';

    private const PENDING_YOUR_CONFIRMATION_LABEL = 'Pending your confirmation';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::PENDING_YOUR_CONFIRMATION => self::PENDING_YOUR_CONFIRMATION_LABEL,
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::COMPLETED => 'Completed',
        };
    }

    /** Badge label for list UI */
    public function listLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::PENDING_YOUR_CONFIRMATION => self::PENDING_YOUR_CONFIRMATION_LABEL,
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::COMPLETED => 'Completed',
        };
    }

    /** Status text for detail "Report Submitted" card */
    public function detailStatusLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::PENDING_YOUR_CONFIRMATION => self::PENDING_YOUR_CONFIRMATION_LABEL,
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::COMPLETED => 'Completed',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    /** Assigned to staff, they have not submitted yet */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /** Staff submitted, awaiting manager confirmation */
    public function isPendingYourConfirmation(): bool
    {
        return $this === self::PENDING_YOUR_CONFIRMATION;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
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

    /** Report has been submitted (waiting confirmation or completed) */
    public function isSubmitted(): bool
    {
        return $this->isPendingYourConfirmation() || $this->isCompleted();
    }

    /** Editable by assignee: draft (manager) or pending (staff still working) */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::PENDING;
    }
}
