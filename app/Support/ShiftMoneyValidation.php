<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Validation for legacy SAR inputs; limits follow the existing Shift columns. */
final class ShiftMoneyValidation
{
    // cashier_shifts, shift_sales_breakdown, variance details and manager totals: DECIMAL(12,2).
    public const SAR = 'numeric|regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/|min:0|max:9999999999.99';

    public const SIGNED_SAR = 'numeric|regex:/\A-?[0-9]+(?:\.[0-9]{1,2})?\z/|min:-9999999999.99|max:9999999999.99';

    // branch_manager_shifts.handover_amount is DECIMAL(10,2), unlike cashier handovers.
    public const MANAGER_HANDOVER_SAR = 'numeric|regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/|min:0|max:99999999.99';

    /**
     * Largest distance (SAR) between a received text amount and its two-decimal value that is still
     * treated as binary floating-point representation noise rather than an extra decimal place.
     */
    public const REPRESENTATION_TOLERANCE_SAR = 0.000001;

    /** Legacy Shift money inputs (dot paths; `*` matches one array level). Absent fields are ignored. */
    public const LEGACY_MONEY_FIELDS = [
        'total_sales',
        'current_sales',
        'cash_collected',
        'card_payments',
        'aggregator_payments',
        'handover_amount',
        'confirmed_amount',
        'aggregators.*.amount',
        'current_cashier_amount',
        'other_cashiers.*.amount',
        'variance.current_cashier_amount',
        'variance.other_cashiers.*.amount',
        'cashier_breakdown.*.sales',
        'cashier_breakdown.*.cash_collected',
        'cashier_breakdown.*.card_payments',
        'cashier_breakdown.*.delivery_app_payments',
        'cashier_breakdown.*.variance',
    ];

    /**
     * Replace text amounts that differ from a two-decimal SAR value only by binary representation
     * noise with that two-decimal value, before validation runs.
     *
     * AssabAPP derives some amounts with Dart doubles (for example `variance.current_cashier_amount`
     * = total − payments) and sends the double's shortest text, such as "0.09999999999999432" or
     * "1.4210854715202004e-14". Those are normalized ("0.10", "0.00"). A real extra decimal place such
     * as "1.001", "0.009" or "123.456" is farther than REPRESENTATION_TOLERANCE_SAR from any two-decimal
     * value, stays unchanged, and is still rejected by the SAR rules with HTTP 422. Exponent text is
     * normalized only when it is noise around zero (Dart prints |x| < 1e-6 that way); "1e2" or "1e-2"
     * stay unchanged and are rejected. JSON numbers are left unchanged.
     *
     * @param  list<string>  $fields
     */
    public static function normalizeRepresentationNoise(Request $request, array $fields = self::LEGACY_MONEY_FIELDS): void
    {
        $input = $request->input();
        if (! is_array($input)) {
            return;
        }

        $changedRoots = [];
        foreach ($fields as $pattern) {
            foreach (self::expandPaths($input, explode('.', $pattern), '') as $path) {
                $normalized = self::withoutRepresentationNoise(data_get($input, $path));
                if ($normalized !== null) {
                    data_set($input, $path, $normalized);
                    $changedRoots[explode('.', $path, 2)[0]] = true;
                }
            }
        }

        if ($changedRoots !== []) {
            $request->merge(array_intersect_key($input, $changedRoots));
        }
    }

    /**
     * The two-decimal text for a value whose only extra digits are representation noise; null when the
     * value is not such a text amount (including ordinary valid amounts, which need no change).
     */
    public static function withoutRepresentationNoise(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A-?[0-9]+(?:\.[0-9]{3,}|(?:\.[0-9]+)?[eE]-[0-9]+)\z/', $value)) {
            return null;
        }

        $amount = (float) $value;
        if (! is_finite($amount)) {
            return null;
        }

        $rounded = round($amount, 2);
        if (abs($amount - $rounded) > self::REPRESENTATION_TOLERANCE_SAR) {
            return null;
        }

        if (stripos($value, 'e') !== false && $rounded != 0.0) {
            return null;
        }

        return number_format($rounded == 0.0 ? 0.0 : $rounded, 2, '.', '');
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function expandPaths(mixed $node, array $segments, string $prefix): array
    {
        if ($segments === []) {
            return $prefix === '' ? [] : [$prefix];
        }

        $segment = array_shift($segments);
        if (! is_array($node)) {
            return [];
        }

        $keys = $segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []);
        $paths = [];
        foreach ($keys as $key) {
            $childPath = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            array_push($paths, ...self::expandPaths($node[$key], $segments, $childPath));
        }

        return $paths;
    }
}
