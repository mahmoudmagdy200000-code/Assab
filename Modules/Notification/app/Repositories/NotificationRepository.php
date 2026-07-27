<?php

namespace Modules\Notification\Repositories;

use Illuminate\Notifications\DatabaseNotification;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Models\NotificationLog;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function record(object $notifiable, string $id, string $notificationClass, array $payload): void
    {
        DatabaseNotification::query()->create([
            'id' => $id,
            'type' => $notificationClass,
            'notifiable_type' => method_exists($notifiable, 'getMorphClass')
                ? $notifiable->getMorphClass()
                : $notifiable::class,
            'notifiable_id' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : $notifiable->id,
            'data' => $payload,
            'read_at' => null,
        ]);
    }

    public function logDelivery(
        string $notificationId,
        NotificationChannel $channel,
        string $status,
        ?string $errorMessage = null,
    ): void {
        NotificationLog::create([
            'notification_id' => $notificationId,
            'channel' => $channel->value,
            'status' => $status,
            'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
