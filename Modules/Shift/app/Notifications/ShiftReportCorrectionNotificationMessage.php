<?php

namespace Modules\Shift\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ShiftReportCorrectionNotificationMessage extends Notification
{
    use Queueable;

    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $payload
    ) {
        $this->id = $eventId;
    }

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
        ];
    }

    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
