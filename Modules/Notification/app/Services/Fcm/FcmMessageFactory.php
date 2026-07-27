<?php

namespace Modules\Notification\Services\Fcm;

use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Services\NotificationCopyResolver;

/**
 * Renders a NotificationType + payload into a transport-ready push message.
 */
class FcmMessageFactory
{
    public function __construct(
        private readonly NotificationCopyResolver $copy,
        private readonly ?string $defaultAndroidChannelId = null,
        private readonly ?int $ttlSeconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function make(
        NotificationType $type,
        array $data,
        NotificationPriority $priority,
        string $locale,
        ?string $notificationId = null,
    ): FcmMessage {
        return new FcmMessage(
            title: $this->copy->title($type, $data, $locale),
            body: $this->copy->body($type, $data, $locale),
            // Clients route on these. Kept flat and stable — FCM data values
            // must be strings, so nested structures are dropped.
            data: array_merge($this->scalarsOnly($data), array_filter([
                'notification_id' => $notificationId,
                'type' => $type->value,
                'category' => $type->category()->value,
                'priority' => $priority->value,
                'sent_at' => now()->toIso8601String(),
            ])),
            priority: $priority,
            androidChannelId: $this->defaultAndroidChannelId,
            // Collapsing on type means a device that was offline through five
            // "shift start reminder" pushes wakes to one, not five.
            collapseKey: $type->value,
            ttlSeconds: $this->ttlSeconds,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function scalarsOnly(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $out;
    }
}
