<?php

namespace Modules\RecurringOrder\Enums;

enum RecurringOrderStatus: string
{
    case GENERATED = 'generated';
    case IN_PROGRESS = 'in_progress';
    case PENDING = 'pending';
    case PAUSED = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::GENERATED => 'Generated',
            self::IN_PROGRESS => 'In Progress',
            self::PENDING => 'Pending',
            self::PAUSED => 'Paused',
        };
    }

    public function isInProgressList(): bool
    {
        return in_array($this, [self::GENERATED, self::IN_PROGRESS]);
    }

    public function isNextSchedulingList(): bool
    {
        return $this === self::PENDING;
    }

    public function isPausedList(): bool
    {
        return $this === self::PAUSED;
    }
}
