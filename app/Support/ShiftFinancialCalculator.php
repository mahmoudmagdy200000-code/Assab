<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Shared shift arithmetic at the integer-halalas boundary.
 *
 * The current SAR columns store two decimal places. For tax extraction this
 * calculator retains the existing nearest-halalah behavior and explicitly
 * marks values needing that technical choice while D5 remains under review.
 */
class ShiftFinancialCalculator
{
    /**
     * Pending incoming is source-backed cash physically included in the count,
     * still owned by the sender. Callers must resolve its transfer evidence;
     * a pending request alone does not establish physical presence.
     *
     * @return array{net:int,vat:int,expected:int,reconciledCounted:int,variance:int,shortage:int,surplus:int,varianceType:string,roundingPendingD5:bool}
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
        // Integer arithmetic avoids binary-float drift. The remainder marks
        // cases whose final halala depends on the pending D5 rounding decision.
        $netWhole = intdiv($grossHalalas, 23) * 20;
        $netFractionNumerator = ($grossHalalas % 23) * 20;
        $netHalalas = $netWhole + intdiv($netFractionNumerator, 23);
        $remainder = $netFractionNumerator % 23;
        $roundingPendingD5 = $remainder !== 0;
        if ($roundingPendingD5 && $remainder * 2 >= 23) {
            // This is the existing SAR DECIMAL(…,2)/request compatibility
            // behavior, surfaced as pending instead of being treated as an
            // approved business rule.
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
            'roundingPendingD5' => $roundingPendingD5,
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
     * @return array{net:string,vat:string,rounding_pending_d5:bool}
     */
    public static function calculateVatInclusiveSales(string|int|float $grossSar): array
    {
        $grossHalalas = self::sarToHalalas($grossSar);
        $calculation = self::calculate($grossHalalas, 0, 0, 0, 0);

        return [
            'net' => number_format($calculation['net'] / 100, 2, '.', ''),
            'vat' => number_format($calculation['vat'] / 100, 2, '.', ''),
            'rounding_pending_d5' => $calculation['roundingPendingD5'],
        ];
    }
}
