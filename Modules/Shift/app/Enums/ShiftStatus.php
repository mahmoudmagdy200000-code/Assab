<?php

namespace Modules\Shift\Enums;

/**
 * Enum representing the status of a shift
 */
enum ShiftStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELED = 'canceled';
    case REASSIGNED = 'reassigned';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Not Started',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::CANCELED => 'Canceled',
            self::REASSIGNED => 'Reassigned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'gray',
            self::IN_PROGRESS => 'blue',
            self::COMPLETED => 'green',
            self::CANCELED => 'red',
            self::REASSIGNED => 'yellow',
        };
    }
}
