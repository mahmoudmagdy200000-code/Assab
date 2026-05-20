<?php

namespace Modules\Notification\Services;

use Illuminate\Notifications\Notifiable;
use Modules\Notification\Contracts\ChannelServiceInterface;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\Channels\EmailChannelService;
use Modules\Notification\Services\Channels\PushChannelService;
use Modules\Notification\Services\Channels\SmsChannelService;

class ChannelService implements ChannelServiceInterface
{
    public function __construct(
        private EmailChannelService $emailChannel,
        private SmsChannelService $smsChannel,
        private PushChannelService $pushChannel
    ) {}

    /**
     * Send notification via specific channel
     */
    public function send(
        Notifiable $notifiable,
        NotificationChannel $channel,
        string $title,
        string $message,
        array $data = []
    ): bool {
        return match ($channel) {
            NotificationChannel::EMAIL => $this->emailChannel->send($notifiable, $title, $message, $data),
            NotificationChannel::SMS => $this->smsChannel->send($notifiable, $title, $message, $data),
            NotificationChannel::PUSH => $this->pushChannel->send($notifiable, $title, $message, $data),
            NotificationChannel::IN_APP => true, // Handled by Laravel notifications
        };
    }
}
