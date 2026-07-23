<?php

namespace Modules\Admin\Services\Notifications;

/**
 * A ready-to-open outbound message for a sent supplier order (FR-PUR-1
 * «إرسال للمورد»). The dashboard opens {@see self::$url}; when the supplier has
 * no dialable phone the URL is null and {@see self::$deliverable} is false so the
 * UI can fall back gracefully instead of opening a broken link.
 */
final class SupplierDispatch
{
    public function __construct(
        /** Delivery channel, e.g. `whatsapp`. */
        public readonly string $channel,
        /** The order/batch number shown to the supplier (FR-PUR-1). */
        public readonly string $reference,
        public readonly string $supplierName,
        /** Normalised E.164 digits (no `+`), or null when none is dialable. */
        public readonly ?string $phone,
        /** The composed message body (kept so non-link channels can reuse it). */
        public readonly string $message,
        /** The click-to-chat deep link, or null when there is no phone. */
        public readonly ?string $url,
    ) {}

    public function deliverable(): bool
    {
        return $this->url !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'reference' => $this->reference,
            'supplierName' => $this->supplierName,
            'phone' => $this->phone,
            'message' => $this->message,
            'url' => $this->url,
            'deliverable' => $this->deliverable(),
        ];
    }
}
