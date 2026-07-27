<?php

namespace Modules\Notification\DataTransferObjects;

use Modules\Notification\Enums\NotificationPriority;

/**
 * Transport-agnostic push payload. Kept free of Firebase wire-format details so
 * an alternative client (APNs direct, Huawei HMS) can consume the same object.
 */
final class FcmMessage
{
    /**
     * @param  array<string, scalar|null>  $data  FCM data payloads are string-only; values are cast on serialisation.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly NotificationPriority $priority = NotificationPriority::LOW,
        public readonly ?string $imageUrl = null,
        public readonly ?string $androidChannelId = null,
        public readonly ?string $collapseKey = null,
        public readonly ?int $ttlSeconds = null,
        public readonly ?int $badge = null,
        public readonly ?string $clickAction = null,
    ) {}

    /**
     * FCM rejects non-string values inside `data`. Booleans become "1"/"0" and
     * nulls are dropped rather than serialised as the string "null".
     *
     * @return array<string, string>
     */
    public function stringData(): array
    {
        $out = [];

        foreach ($this->data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $out[(string) $key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * High-priority delivery wakes a doze-mode Android device and sets
     * apns-priority 10. Reserved for HIGH/CRITICAL so routine traffic does not
     * burn the app's FCM priority budget.
     */
    public function isHighPriority(): bool
    {
        return $this->priority->numericValue() >= NotificationPriority::HIGH->numericValue();
    }
}
