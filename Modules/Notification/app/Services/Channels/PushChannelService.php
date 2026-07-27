<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Notification\DataTransferObjects\NotificationEnvelope;
use Modules\Notification\Jobs\SendFcmMessageJob;
use Psr\Log\LoggerInterface;

/**
 * Device push over Firebase Cloud Messaging.
 *
 * This used to be a stub that returned true without sending anything. It now
 * queues one job per recipient; the job resolves that recipient's devices,
 * renders per-device-locale copy and prunes tokens FCM rejects.
 *
 * Distinct from the Pusher broadcast in NotificationBroadcasted: Pusher drives
 * the live in-app UI of an *open* client, FCM reaches a backgrounded or killed
 * app. Both can be enabled for the same notification.
 */
class PushChannelService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(NotificationEnvelope $envelope): bool
    {
        $notifiableId = $envelope->notifiableId();

        if ($notifiableId === null) {
            $this->logger->warning('Push notification skipped: recipient has no key', [
                'notifiable_type' => $envelope->notifiableType(),
                'type' => $envelope->type->value,
            ]);

            return false;
        }

        if (! method_exists($envelope->notifiable, 'deviceTokens')) {
            $this->logger->warning('Push notification skipped: recipient is not push-addressable', [
                'notifiable_type' => $envelope->notifiableType(),
                'type' => $envelope->type->value,
            ]);

            return false;
        }

        $this->dispatcher->dispatch(new SendFcmMessageJob(
            notifiableType: $envelope->notifiableType(),
            notifiableId: $notifiableId,
            type: $envelope->type,
            data: $envelope->data,
            priority: $envelope->priority,
            notificationId: $envelope->notificationId,
        ));

        // True means "accepted for delivery". The queued job owns the real
        // per-device outcome and writes its own NotificationLog row.
        return true;
    }
}
