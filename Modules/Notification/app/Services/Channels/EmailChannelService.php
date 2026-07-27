<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Contracts\Mail\Mailer;
use Modules\Notification\DataTransferObjects\NotificationEnvelope;
use Modules\Notification\Mail\NotificationMail;
use Psr\Log\LoggerInterface;

class EmailChannelService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(NotificationEnvelope $envelope): bool
    {
        $notifiable = $envelope->notifiable;
        $email = null;

        try {
            if (method_exists($notifiable, 'routeNotificationForMail')) {
                $email = $notifiable->routeNotificationForMail();
            } elseif (isset($notifiable->email)) {
                $email = $notifiable->email;
            }

            // routeNotificationForMail may return [address => name].
            if (is_array($email)) {
                $email = array_key_first($email);
            }

            if (! is_string($email) || $email === '') {
                $this->logger->warning('No email address found for notifiable', [
                    'notifiable_type' => $envelope->notifiableType(),
                    'notifiable_id' => $envelope->notifiableId(),
                ]);

                return false;
            }

            $this->mailer->to($email)->send(
                new NotificationMail($envelope->title, $envelope->message, $envelope->data)
            );

            return true;
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface|\Illuminate\Database\QueryException $e) {
            $this->logger->error('Email notification failed', [
                'notifiable_type' => $envelope->notifiableType(),
                'type' => $envelope->type->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
