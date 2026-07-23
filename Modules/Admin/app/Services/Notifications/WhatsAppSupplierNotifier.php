<?php

namespace Modules\Admin\Services\Notifications;

/**
 * WhatsApp click-to-chat delivery for «إرسال للمورد» (FR-PUR-1). Produces a
 * `wa.me` deep link with the order pre-filled as text; the purchase officer
 * sends it from their own WhatsApp, so no gateway credentials are required.
 * Swap this binding for a gateway-backed notifier when automated send is
 * provisioned — the {@see SupplierOrderNotifier} contract stays the same.
 */
class WhatsAppSupplierNotifier implements SupplierOrderNotifier
{
    /** Default dialing code — the platform targets the Saudi market (CLAUDE.md). */
    private const DEFAULT_COUNTRY_CODE = '966';

    private const SEPARATOR = '━━━━━━━━━━';

    public function forOrder(
        string $reference,
        string $supplierName,
        ?string $phone,
        array $lines,
        ?string $eta = null,
    ): SupplierDispatch {
        $digits = $this->normalisePhone($phone);
        $message = $this->compose($reference, $supplierName, $lines, $eta);

        return new SupplierDispatch(
            channel: 'whatsapp',
            reference: $reference,
            supplierName: $supplierName,
            phone: $digits,
            message: $message,
            url: $digits === null ? null : 'https://wa.me/'.$digits.'?text='.rawurlencode($message),
        );
    }

    /**
     * Reduce a phone to E.164 digits (no `+`), assuming the Saudi default when no
     * country code is present. Returns null when there is nothing dialable.
     *
     * `+966 55 342 1100` → `966553421100`; `00966553421100` → `966553421100`;
     * `0553421100` → `966553421100`; `553421100` → `966553421100`.
     */
    private function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        // A number of only zeros carries no signal.
        if ($digits === '' || ltrim($digits, '0') === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            // International prefix (any country) → the digits after it are E.164.
            return substr($digits, 2);
        }
        if (str_starts_with($digits, self::DEFAULT_COUNTRY_CODE)) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            // Local national form → drop the trunk zero, prepend the country code.
            return self::DEFAULT_COUNTRY_CODE.substr($digits, 1);
        }

        // Bare subscriber number → assume the default market.
        return self::DEFAULT_COUNTRY_CODE.$digits;
    }

    /**
     * The Arabic order body: header (order number + supplier), then a numbered
     * item list (`name × qty unit`), then the optional ETA.
     *
     * @param  array<int, array{name: string, qty?: string|null, unit?: string|null}>  $lines
     */
    private function compose(string $reference, string $supplierName, array $lines, ?string $eta): string
    {
        $out = [
            'طلب شراء رقم: '.$reference,
            'المورد: '.$supplierName,
            self::SEPARATOR,
        ];

        $n = 1;
        foreach ($lines as $line) {
            $name = trim((string) ($line['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty = isset($line['qty']) ? trim((string) $line['qty']) : '';
            $unit = trim((string) ($line['unit'] ?? ''));

            $row = $n.'. '.$name;
            if ($qty !== '') {
                $row .= ' × '.$qty.($unit !== '' ? ' '.$unit : '');
            }
            $out[] = $row;
            $n++;
        }
        if ($n === 1) {
            $out[] = '(لا توجد أصناف)';
        }

        if ($eta !== null && $eta !== '') {
            $out[] = self::SEPARATOR;
            $out[] = 'موعد التسليم المتوقع: '.$eta;
        }

        return implode("\n", $out);
    }
}
