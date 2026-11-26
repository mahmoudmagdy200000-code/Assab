<?php

namespace Modules\Purchase\Services;

use Carbon\Carbon;
use Modules\Purchase\Enums\OrderItemStatus;

class CalculationService
{
    /**
     * VAT rate (15% for Saudi Arabia)
     */
    private const VAT_RATE = 15.00;

    /**
     * Default payment terms in days
     */
    private const DEFAULT_PAYMENT_TERMS = 30;

    /**
     * Calculate VAT amount
     */
    public function calculateVAT(float $amount, ?float $rate = null): float
    {
        if ($rate === null) {
            throw new \InvalidArgumentException('VAT rate is required (0% or 15%) and cannot be defaulted.');
        }

        $vatRate = (float) $rate;
        if (! in_array($vatRate, [0.0, 15.0], true)) {
            throw new \InvalidArgumentException('VAT rate must be 0% or 15%.');
        }

        return round($amount * ($vatRate / 100), 2);
    }

    /**
     * Calculate total with VAT
     */
    public function calculateTotalWithVAT(float $amount, ?float $rate = null): array
    {
        $vatAmount = $this->calculateVAT($amount, $rate);
        $total = round($amount + $vatAmount, 2);

        return [
            'amount_before_tax' => round($amount, 2),
            'vat_rate' => (float) $rate,
            'vat_amount' => $vatAmount,
            'total_amount' => $total,
        ];
    }

    /**
     * Calculate due date based on invoice date and payment terms
     */
    public function calculateDueDate($invoiceDate, ?int $days = null): Carbon
    {
        $date = $invoiceDate instanceof Carbon ? $invoiceDate : Carbon::parse($invoiceDate);
        $paymentDays = $days ?? self::DEFAULT_PAYMENT_TERMS;

        return $date->addDays($paymentDays);
    }

    /**
     * Calculate variance amount
     */
    public function calculateVarianceAmount(float $orderedQty, float $receivedQty, float $unitPrice): float
    {
        $variance = $orderedQty - $receivedQty;

        return round(abs($variance) * $unitPrice, 2);
    }

    /**
     * Calculate short quantity
     */
    public function calculateShortQuantity(float $ordered, float $received): float
    {
        return max(0, $ordered - $received);
    }

    /**
     * Calculate order totals
     */
    public function calculateOrderTotals(array $items, float $discountAmount = 0, ?float $taxRate = null): array
    {
        if ($taxRate === null) {
            throw new \InvalidArgumentException('Order tax rate is required (0% or 15%) and cannot be defaulted.');
        }

        $rate = (float) $taxRate;
        if (! in_array($rate, [0.0, 15.0], true)) {
            throw new \InvalidArgumentException('Order tax rate must be 0% or 15%.');
        }

        $excludedStatuses = array_merge(
            OrderItemStatus::cancelledStatusValues(),
            [OrderItemStatus::REJECTED->value]
        );

        $subtotal = 0.0;
        $activeCount = 0;

        foreach ($items as $item) {
            $status = $item['status'] ?? null;
            if ($status instanceof OrderItemStatus) {
                $status = $status->value;
            }
            if ($status !== null && in_array((string) $status, $excludedStatuses, true)) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? $item['quantity_ordered'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $itemDiscount = (float) ($item['discount'] ?? 0);

            $subtotal += round(($quantity * $unitPrice) - $itemDiscount, 2);
            $activeCount++;
        }

        $subtotal = round($subtotal, 2);
        $effectiveDiscount = min($subtotal, max(0.0, round($discountAmount, 2)));
        $taxableBase = max(0.0, round($subtotal - $effectiveDiscount, 2));
        $taxAmount = $this->calculateVAT($taxableBase, $rate);
        $total = round($taxableBase + $taxAmount, 2);

        return [
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'discount_amount' => $effectiveDiscount,
            'total_amount' => $total,
            'total_items' => $activeCount,
        ];
    }

    /**
     * Calculate savings between two prices
     */
    public function calculateSavings(float $originalPrice, float $newPrice): array
    {
        $savingsAmount = $originalPrice - $newPrice;
        $savingsPercentage = $originalPrice > 0
            ? ($savingsAmount / $originalPrice) * 100
            : 0;

        return [
            'original_price' => round($originalPrice, 2),
            'new_price' => round($newPrice, 2),
            'savings_amount' => round($savingsAmount, 2),
            'savings_percentage' => round($savingsPercentage, 2),
        ];
    }

    /**
     * Calculate return amount
     */
    public function calculateReturnAmount(float $quantity, float $unitPrice): float
    {
        return round($quantity * $unitPrice, 2);
    }

    /**
     * Calculate total return value
     */
    public function calculateTotalReturnValue(array $items): float
    {
        return collect($items)->sum(function ($item) {
            $quantity = $item['return_quantity'] ?? $item['quantity'] ?? 0;
            $unitPrice = $item['unit_price'] ?? 0;

            return $quantity * $unitPrice;
        });
    }

    /**
     * Calculate invoice deduction
     */
    public function calculateInvoiceDeduction(float $originalAmount, float $deductionAmount): array
    {
        $finalAmount = max(0, $originalAmount - $deductionAmount);

        return [
            'original_amount' => round($originalAmount, 2),
            'deduction_amount' => round($deductionAmount, 2),
            'final_amount' => round($finalAmount, 2),
        ];
    }

    /**
     * Calculate availability percentage
     */
    public function calculateAvailabilityPercentage(float $available, float $requested): float
    {
        if ($requested <= 0) {
            return 0;
        }

        return round(min(100, ($available / $requested) * 100), 2);
    }

    /**
     * Calculate balance quantity after modification
     */
    public function calculateBalanceQuantity(float $original, float $new): float
    {
        return $original - $new;
    }

    /**
     * Calculate expected amount from items
     */
    public function calculateExpectedAmount(array $items): float
    {
        return collect($items)->sum(function ($item) {
            $quantity = $item['quantity_ordered'] ?? $item['quantity'] ?? 0;
            $unitPrice = $item['unit_price'] ?? 0;

            return $quantity * $unitPrice;
        });
    }

    /**
     * Calculate received amount from items
     */
    public function calculateReceivedAmount(array $items): float
    {
        return collect($items)->sum(function ($item) {
            $quantity = $item['quantity_received'] ?? 0;
            $unitPrice = $item['unit_price'] ?? 0;

            return $quantity * $unitPrice;
        });
    }

    /**
     * Format currency
     */
    public function formatCurrency(float $amount, string $currency = 'SAR'): string
    {
        return $currency.' '.number_format($amount, 2);
    }

    /**
     * Calculate price per quality level
     */
    public function getPriceByQuality(float $basePrice, string $quality): float
    {
        $multiplier = match ($quality) {
            'economy' => 0.85,
            'premium' => 1.25,
            default => 1.0, // standard
        };

        return round($basePrice * $multiplier, 2);
    }
}
