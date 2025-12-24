<?php

namespace Modules\Notification\Contracts;

use Illuminate\Notifications\Notifiable;
use Modules\Notification\Enums\NotificationChannel;

interface ChannelServiceInterface
{
    /**
     * Send notification via specific channel
     */
    public function send(
        Notifiable $notifiable,
        NotificationChannel $channel,
        string $title,
        string $message,
        array $data = []
    ): bool;
}

