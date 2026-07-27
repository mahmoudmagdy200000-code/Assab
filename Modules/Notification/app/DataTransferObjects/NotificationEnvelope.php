<?php

namespace Modules\Notification\DataTransferObjects;

use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

/**
 * Everything a channel needs to deliver one notification to one recipient.
 *
 * Replaces the previous (notifiable, title, message, data) parameter list, whose
 * first parameter was type-hinted against the `Notifiable` *trait* — a
 * declaration PHP can never satisfy, so every email and SMS send raised a
 * TypeError before reaching the provider.
 */
final class NotificationEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly object $notifiable,
        public readonly NotificationType $type,
        public readonly array $data,
        public readonly NotificationPriority $priority,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $notificationId = null,
    ) {}

    public function notifiableType(): string
    {
        return method_exists($this->notifiable, 'getMorphClass')
            ? $this->notifiable->getMorphClass()
            : $this->notifiable::class;
    }

    public function notifiableId(): ?string
    {
        $key = method_exists($this->notifiable, 'getKey')
            ? $this->notifiable->getKey()
            : ($this->notifiable->id ?? null);

        return $key === null ? null : (string) $key;
    }

    public function withNotificationId(?string $notificationId): self
    {
        return new self(
            $this->notifiable,
            $this->type,
            $this->data,
            $this->priority,
            $this->title,
            $this->message,
            $notificationId,
        );
    }
}
