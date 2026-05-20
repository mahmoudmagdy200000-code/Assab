<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\SmsRateLimit;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;

class SmsChannelService
{
    private const MAX_SMS_PER_DAY = 10;

    public function __construct(
        private SmsProviderInterface $smsProvider
    ) {}

    /**
     * Send SMS notification
     */
    public function send(Notifiable $notifiable, string $title, string $message, array $data = []): bool
    {
        try {
            // Check rate limit
            if (! $this->checkRateLimit($notifiable)) {
                Log::warning('SMS rate limit exceeded', [
                    'notifiable_id' => $notifiable->id ?? null,
                ]);

                return false;
            }

            if (! method_exists($notifiable, 'routeNotificationForSms')) {
                Log::warning('Notifiable does not have SMS route', [
                    'notifiable_id' => $notifiable->id ?? null,
                ]);

                return false;
            }

            $phoneNumber = $notifiable->routeNotificationForSms();

            if (! $phoneNumber) {
                Log::warning('No phone number found for notifiable', [
                    'notifiable_id' => $notifiable->id ?? null,
                ]);

                return false;
            }

            // Send SMS
            $success = $this->smsProvider->send($phoneNumber, $message);

            if ($success) {
                $this->incrementRateLimit($notifiable);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('SMS notification failed', [
                'phone' => $phoneNumber ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if SMS can be sent (rate limit)
     */
    private function checkRateLimit(Notifiable $notifiable): bool
    {
        $rateLimit = SmsRateLimit::firstOrCreate(
            [
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'date' => now()->toDateString(),
            ],
            ['count' => 0]
        );

        return $rateLimit->canSend(self::MAX_SMS_PER_DAY);
    }

    /**
     * Increment SMS rate limit counter
     */
    private function incrementRateLimit(Notifiable $notifiable): void
    {
        $rateLimit = SmsRateLimit::firstOrCreate(
            [
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'date' => now()->toDateString(),
            ],
            ['count' => 0]
        );

        $rateLimit->incrementCount();
    }
}
