<?php

namespace Modules\Notification\Services\Channels;

use Modules\Notification\DataTransferObjects\NotificationEnvelope;
use Modules\Notification\Models\SmsRateLimit;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;
use Psr\Log\LoggerInterface;

class SmsChannelService
{
    public function __construct(
        private readonly SmsProviderInterface $smsProvider,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(NotificationEnvelope $envelope): bool
    {
        $notifiable = $envelope->notifiable;
        $phoneNumber = null;

        try {
            if (! $this->checkRateLimit($envelope)) {
                $this->logger->warning('SMS rate limit exceeded', [
                    'notifiable_type' => $envelope->notifiableType(),
                    'notifiable_id' => $envelope->notifiableId(),
                ]);

                return false;
            }

            if (method_exists($notifiable, 'routeNotificationForSms')) {
                $phoneNumber = $notifiable->routeNotificationForSms();
            } elseif (isset($notifiable->phone)) {
                $phoneNumber = $notifiable->phone;
            }

            if (! is_string($phoneNumber) || $phoneNumber === '') {
                $this->logger->warning('No phone number found for notifiable', [
                    'notifiable_type' => $envelope->notifiableType(),
                    'notifiable_id' => $envelope->notifiableId(),
                ]);

                return false;
            }

            $success = $this->smsProvider->send($phoneNumber, $envelope->message);

            if ($success) {
                $this->incrementRateLimit($envelope);
            }

            return $success;
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Database\QueryException $e) {
            $this->logger->error('SMS notification failed', [
                'notifiable_type' => $envelope->notifiableType(),
                'type' => $envelope->type->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function checkRateLimit(NotificationEnvelope $envelope): bool
    {
        return $this->rateLimitRow($envelope)->canSend(
            (int) config('notification.sms.max_per_day', 10)
        );
    }

    private function incrementRateLimit(NotificationEnvelope $envelope): void
    {
        $this->rateLimitRow($envelope)->incrementCount();
    }

    private function rateLimitRow(NotificationEnvelope $envelope): SmsRateLimit
    {
        return SmsRateLimit::firstOrCreate(
            [
                'notifiable_type' => $envelope->notifiableType(),
                'notifiable_id' => $envelope->notifiableId(),
                'date' => now()->toDateString(),
            ],
            ['count' => 0]
        );
    }
}
