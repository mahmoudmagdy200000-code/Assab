<?php

namespace Modules\Admin\Services\Notifications;

/**
 * OCP/DIP seam for FR-PUR-1 «إرسال للمورد» (Send to Supplier). The dashboard
 * depends on this abstraction, never on WhatsApp directly — a future
 * gateway-backed provider (Twilio / Meta Cloud API) is a new container binding,
 * not an edit to any call site.
 */
interface SupplierOrderNotifier
{
    /**
     * Build the outbound order message for a supplier.
     *
     * @param  string  $reference  the order/batch number shown to the supplier
     * @param  string|null  $phone  the supplier's raw phone (any local/international format)
     * @param  array<int, array{name: string, qty?: string|null, unit?: string|null}>  $lines  ordered item rows
     * @param  string|null  $eta  expected delivery date (Y-m-d), optional
     */
    public function forOrder(
        string $reference,
        string $supplierName,
        ?string $phone,
        array $lines,
        ?string $eta = null,
    ): SupplierDispatch;
}
