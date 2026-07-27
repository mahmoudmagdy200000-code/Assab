<?php

namespace Modules\Notification\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\Repositories\DeviceTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Laravel notification channel for FCM, registered as `fcm`.
 *
 * Lets any module notification opt into device push without going through
 * NotificationService:
 *
 *     public function via($notifiable): array { return ['database', 'fcm']; }
 *     public function toFcm($notifiable): FcmMessage { return new FcmMessage(...); }
 *
 * NotificationService remains the richer path — it applies preferences, writes
 * delivery logs and mirrors to linked identities. Use this channel for
 * standalone notifications that need none of that.
 */
class FcmChannel
{
    public function __construct(
        private readonly FcmClientInterface $client,
        private readonly DeviceTokenRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $message = $notification->toFcm($notifiable);

        if (! $message instanceof FcmMessage) {
            $this->logger->warning('toFcm() must return an FcmMessage', [
                'notification' => $notification::class,
            ]);

            return;
        }

        if (! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $devices = $this->repository->forOwner($notifiable);
        $prune = [];
        $delivered = [];

        foreach ($devices as $device) {
            $result = $this->client->sendToToken($device->token, $message);

            if ($result->success) {
                $delivered[] = $device->token;
            } elseif ($result->shouldPrune) {
                $prune[] = $device->token;
            }
        }

        if ($prune !== []) {
            $this->repository->forgetTokens($prune);
        }

        if ($delivered !== []) {
            $this->repository->markUsed($delivered);
        }
    }
}
