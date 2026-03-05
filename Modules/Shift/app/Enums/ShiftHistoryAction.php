<?php

namespace Modules\Shift\Enums;

enum ShiftHistoryAction: string
{
    case SHIFT_STARTED = 'shift_started';
    case SHIFT_ENDED = 'shift_ended';
    case HANDOVER_RECORDED = 'handover_recorded';
    case HANDOVER_ACCEPTED = 'handover_accepted';
    case HANDOVER_APPROVED = 'handover_approved';
    case HANDOVER_REJECTED = 'handover_rejected';
    case SHIFT_REASSIGNED = 'shift_reassigned';
    case SHIFT_CANCELED = 'shift_canceled';

    public function label(): string
    {
        return match ($this) {
            self::SHIFT_STARTED => 'Shift Started',
            self::SHIFT_ENDED => 'Shift Ended',
            self::HANDOVER_RECORDED => 'Handover Recorded',
            self::HANDOVER_ACCEPTED => 'Handover Accepted',
            self::HANDOVER_APPROVED => 'Handover Approved',
            self::HANDOVER_REJECTED => 'Handover Rejected',
            self::SHIFT_REASSIGNED => 'Shift Reassigned',
            self::SHIFT_CANCELED => 'Shift Canceled',
        };
    }
}
