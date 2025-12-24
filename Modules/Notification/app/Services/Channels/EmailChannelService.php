<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Mail\NotificationMail;

class EmailChannelService
{
    /**
     * Send email notification
     */
    public function send(Notifiable $notifiable, string $title, string $message, array $data = []): bool
    {
        try {
            if (!method_exists($notifiable, 'routeNotificationForMail')) {
                Log::warning('Notifiable does not have email route', [
                    'notifiable_id' => $notifiable->id ?? null,
                ]);
                return false;
            }

            $email = $notifiable->routeNotificationForMail();

            if (!$email) {
                Log::warning('No email address found for notifiable', [
                    'notifiable_id' => $notifiable->id ?? null,
                ]);
                return false;
            }

            Mail::to($email)->send(new NotificationMail($title, $message, $data));

            return true;
        } catch (\Exception $e) {
            Log::error('Email notification failed', [
                'email' => $email ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

