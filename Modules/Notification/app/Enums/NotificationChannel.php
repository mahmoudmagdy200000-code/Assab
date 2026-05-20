<?php

namespace Modules\Notification\Enums;

enum NotificationChannel: string
{
    case IN_APP = 'app';
    case EMAIL = 'email';
    case SMS = 'sms';
    case PUSH = 'push';

    public function label(): string
    {
        return match ($this) {
            self::IN_APP => 'In-App',
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::PUSH => 'Push Notification',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::IN_APP => 'bell',
            self::EMAIL => 'mail',
            self::SMS => 'message',
            self::PUSH => 'radio',
        };
    }
}
