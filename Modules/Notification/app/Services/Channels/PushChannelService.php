<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class PushChannelService
{
    /**
     * Send push notification via Pusher
     * 
     * This triggers the broadcast of the notification to the user's private channel
     */
    public function send(Notifiable $notifiable, string $title, string $message, array $data = []): bool
    {
        try {
            // Push notifications are handled via Laravel's broadcasting system
            // The BaseNotification will implement ShouldBroadcast to send via Pusher
            // This method returns true as the actual broadcasting happens in the notification class
            return true;
        } catch (\Exception $e) {
            Log::error('Push notification failed', [
                'notifiable_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

