<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Shared shift arithmetic at the integer-halalas boundary.
 *
 * D5 (approved by Mahmoud): integer-halalas arithmetic; VAT-inclusive net is
 * rounded half-up to the halala and VAT is the residual (gross − net).
 */
class ShiftFinancialCalculator
{
    /**
     * Pending incoming is source-backed cash physically included in the count,
     * still owned by the sender. Callers must resolve its transfer evidence;
     * a pending request alone does not establish physical presence.
     *
     * @return array{net:int,vat:int,expected:int,reconciledCounted:int,variance:int,shortage:int,surplus:int,varianceType:string,netRounded:bool}
     */
    public static function calculate(
        int $grossHalalas,
        int $cardsHalalas,
        int $appsHalalas,
        int $confirmedOpeningHalalas,
        int $countedHalalas,
        int $pendingIncomingCountedHalalas = 0,
    ): array {
        foreach ([$grossHalalas, $cardsHalalas, $appsHalalas, $confirmedOpeningHalalas, $countedHalalas, $pendingIncomingCountedHalalas] as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Shift amounts must be non-negative halalas.');
            }
        }

        if ($pendingIncomingCountedHalalas > $countedHalalas) {
            throw new InvalidArgumentException('Pending incoming counted cash cannot exceed the physical count.');
        }

        // gross SAR / 1.15, represented in halalas, is gross * 20 / 23.
        // Integer arithmetic avoids binary-float drift. A non-zero remainder
        // means net was rounded (half-up, D5); 23 is odd, so exact ties cannot occur.
        $netWhole = intdiv($grossHalalas, 23) * 20;
        $netFractionNumerator = ($grossHalalas % 23) * 20;
        $netHalalas = $netWhole + intdiv($netFractionNumerator, 23);
        $remainder = $netFractionNumerator % 23;
        $netRounded = $remainder !== 0;
        if ($netRounded && $remainder * 2 >= 23) {
            $netHalalas++;
        }

        $vatHalalas = $grossHalalas - $netHalalas;
        $expectedHalalas = $grossHalalas - $cardsHalalas - $appsHalalas + $confirmedOpeningHalalas;
        $reconciledCountedHalalas = $countedHalalas - $pendingIncomingCountedHalalas;
        $varianceHalalas = $reconciledCountedHalalas - $expectedHalalas;

        return [
            'net' => $netHalalas,
            'vat' => $vatHalalas,
            'expected' => $expectedHalalas,
            'reconciledCounted' => $reconciledCountedHalalas,
            'variance' => $varianceHalalas,
            'shortage' => max(0, -$varianceHalalas),
            'surplus' => max(0, $varianceHalalas),
            'varianceType' => match (true) {
                $varianceHalalas < 0 => 'shortage',
                $varianceHalalas > 0 => 'surplus',
                default => 'balanced',
            },
            'netRounded' => $netRounded,
        ];
    }

    /**
     * Convert a legacy SAR amount with at most two decimal places to halalas.
     */
    public static function sarToHalalas(string|int|float $amount): int
    {
        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('SAR amount must be finite.');
            }

            $scaled = $amount * 100;
            $rounded = round($scaled);
            // Allow binary representation error at the DECIMAL(12,2) boundary,
            // not an additional decimal place or a business rounding tolerance.
            $representationError = max(0.000001, abs($scaled) * PHP_FLOAT_EPSILON);
            if (abs($scaled - $rounded) > $representationError) {
                throw new InvalidArgumentException('SAR amount must have no more than two decimal places.');
            }

            return (int) $rounded;
        }

        $text = (string) $amount;
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $matches)) {
            throw new InvalidArgumentException('SAR amount must be a non-negative value with no more than two decimal places.');
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    /**
     * @return array{net:string,vat:string,net_rounded:bool}
     */
    public static function calculateVatInclusiveSales(string|int|float $grossSar): array
    {
        $grossHalalas = self::sarToHalalas($grossSar);
        $calculation = self::calculate($grossHalalas, 0, 0, 0, 0);

        return [
            'net' => number_format($calculation['net'] / 100, 2, '.', ''),
            'vat' => number_format($calculation['vat'] / 100, 2, '.', ''),
            'net_rounded' => $calculation['netRounded'],
        ];
    }

    /**
     * Net and VAT of an already persisted row, in halalas.
     *
     * Stored values are historical evidence and are returned unchanged, even when they were written
     * by the pre-S1-06 "gross × 15%" code. Only a row without a stored split (net and VAT both zero
     * while gross is positive) has its split derived from its own gross. Never throws for legacy
     * negative values; correcting historical rows is an explicit, audited data operation, not a read.
     *
     * @return array{net:int,vat:int}
     */
    public static function persistedSalesSplitHalalas(mixed $grossSar, mixed $netSar, mixed $vatSar): array
    {
        $gross = self::storedSarToHalalas($grossSar);
        $net = self::storedSarToHalalas($netSar);
        $vat = self::storedSarToHalalas($vatSar);

        if ($net === 0 && $vat === 0 && $gross > 0) {
            $calculation = self::calculate($gross, 0, 0, 0, 0);

            return ['net' => $calculation['net'], 'vat' => $calculation['vat']];
        }

        return ['net' => $net, 'vat' => $vat];
    }

    /**
     * Convert a stored DECIMAL(…,2) SAR value (decimal:2 cast string, int, float or null) to signed
     * halalas. Unlike request input, stored legacy rows may hold negative values.
     */
    public static function storedSarToHalalas(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        if (is_string($amount) && str_starts_with($amount, '-')) {
            return -self::sarToHalalas(substr($amount, 1));
        }

        if ((is_int($amount) || is_float($amount)) && $amount < 0) {
            return -self::sarToHalalas(-$amount);
        }

        return self::sarToHalalas($amount);
    }
}
