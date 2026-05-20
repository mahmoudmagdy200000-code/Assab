<?php

namespace Modules\Purchase\Enums;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case WHATSAPP = 'whatsapp';
    case APP = 'app';
    case SMS = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::WHATSAPP => 'WhatsApp',
            self::APP => 'In-App',
            self::SMS => 'SMS',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EMAIL => 'mail',
            self::WHATSAPP => 'whatsapp',
            self::APP => 'bell',
            self::SMS => 'message',
        };
    }
}
