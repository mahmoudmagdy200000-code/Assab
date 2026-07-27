<?php

namespace Modules\Notification\Services;

use Modules\Notification\Contracts\ChannelServiceInterface;
use Modules\Notification\DataTransferObjects\NotificationEnvelope;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\Channels\EmailChannelService;
use Modules\Notification\Services\Channels\PushChannelService;
use Modules\Notification\Services\Channels\SmsChannelService;

class ChannelService implements ChannelServiceInterface
{
    public function __construct(
        private readonly EmailChannelService $emailChannel,
        private readonly SmsChannelService $smsChannel,
        private readonly PushChannelService $pushChannel,
    ) {}

    public function send(NotificationEnvelope $envelope, NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::EMAIL => $this->emailChannel->send($envelope),
            NotificationChannel::SMS => $this->smsChannel->send($envelope),
            NotificationChannel::PUSH => $this->pushChannel->send($envelope),
            // The in-app record is written by the database notification channel
            // before any external channel runs; nothing further to do here.
            NotificationChannel::IN_APP => true,
        };
    }
}
