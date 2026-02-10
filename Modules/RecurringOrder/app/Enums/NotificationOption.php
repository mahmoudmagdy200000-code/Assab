<?php

namespace Modules\RecurringOrder\Enums;

enum NotificationOption: string
{
    case ALERT_24_HOURS_BEFORE = 'alert_24_hours_before';
    case REVIEW_BEFORE_SENDING = 'review_before_sending';
    case SEND_AUTOMATICALLY_WITHOUT_REVIEW = 'send_automatically_without_review';

    public function label(): string
    {
        return match ($this) {
            self::ALERT_24_HOURS_BEFORE => 'Alert 24 Hours Before',
            self::REVIEW_BEFORE_SENDING => 'Review Before Sending',
            self::SEND_AUTOMATICALLY_WITHOUT_REVIEW => 'Send Automatically Without Review',
        };
    }
}
