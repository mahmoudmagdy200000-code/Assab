<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PriceComparisonResource extends JsonResource
{
    public function toArray($request): array
    {
        $directSupplier = $this->resource['sources']['direct_supplier'] ?? [];
        $purchasingOfficer = $this->resource['sources']['via_purchasing_officer'] ?? null;
        $internalTransfer = $this->resource['sources']['internal_transfer'] ?? [];

        return [
            'item_id' => $this->resource['item_id'] ?? null,
            'item_name' => $this->resource['item_name'] ?? null,
            'item_code' => $this->resource['item_code'] ?? null,
            'item_unit' => $this->resource['item_unit'] ?? null,
            'item_logo' => $this->resource['item_logo'] ?? null,
            'item_price' => $this->resource['item_price'] ?? null,
            'quantity' => $this->resource['quantity'] ?? null,

            // Sources
            'sources' => [
                'direct_supplier' => $this->summarizeDirectSupplier($directSupplier),
                'via_purchasing_officer' => $this->summarizeSimpleSource($purchasingOfficer),
                'internal_transfer' => $this->summarizeSimpleSource($internalTransfer),
            ],

            // Best option
            'best_option' => $this->resource['best_option'] ?? null,

            // Explicit use recommendation object (type + id + name)
            'use_recommendation' => $this->formatUseRecommendation($this->resource['best_option'] ?? null),

            // Factors with source_type/source_id
            'factors' => $this->formatFactors($this->resource['insights'] ?? []),

            // Insights
            'insights' => [
                'lowest_price' => $this->resource['insights']['lowest_price'] ?? null,
                'fastest_delivery' => $this->resource['insights']['fastest_delivery'] ?? null,
                'best_rating' => $this->resource['insights']['best_rating'] ?? null,
                'best_compliance' => $this->resource['insights']['best_compliance'] ?? null,
            ],

            // Price trends
            'price_trends' => $this->resource['price_trends'] ?? [],
        ];
    }

    /**
     * Normalize direct supplier to a single summary object.
     */
    private function summarizeDirectSupplier(array $suppliers): array
    {
        $best = empty($suppliers)
            ? null
            : collect($suppliers)->sortBy('unit_price')->first();

        return [
            'price' => isset($best['unit_price'])
                ? number_format((float) $best['unit_price'], 2, '.', '')
                : null,
            'delivery_days' => isset($best['delivery_days'])
                ? number_format((float) $best['delivery_days'], 1, '.', '')
                : null,
            'rating' => isset($best['rating'])
                ? number_format((float) $best['rating'], 2, '.', '')
                : null,
        ];
    }

    /**
     * Ensure purchasing officer block has consistent formatting.
     */
    private function summarizeSimpleSource($source): array
    {
        // Accepts either a single associative array or an array of options; picks the lowest price if multiple
        if (empty($source)) {
            return [
                'price' => null,
                'delivery_days' => null,
                'rating' => null,
            ];
        }

        $normalized = is_array($source) && array_is_list($source)
            ? collect($source)->sortBy(fn($item) => $item['unit_price'] ?? $item['price'] ?? PHP_FLOAT_MAX)->first()
            : $source;

        $price = $normalized['unit_price'] ?? $normalized['price'] ?? null;

        return [
            'price' => $price !== null
                ? number_format((float) $price, 2, '.', '')
                : null,
            'delivery_days' => isset($normalized['delivery_days'])
                ? number_format((float) $normalized['delivery_days'], 1, '.', '')
                : null,
            'rating' => isset($normalized['rating'])
                ? number_format((float) $normalized['rating'], 2, '.', '')
                : null,
        ];
    }

    private function formatUseRecommendation(?array $bestOption): ?array
    {
        if (!$bestOption) {
            return null;
        }

        return [
            'source_type' => $bestOption['source_type'] ?? $bestOption['type'] ?? null,
            'source_id' => $bestOption['source_id'] ?? null,
            'source_name' => $bestOption['source_name'] ?? null,
            'reason' => $bestOption['reason'] ?? null,
        ];
    }

    private function formatFactors(array $insights): array
    {
        return [
            'best_compliance' => $this->mapFactor($insights['best_compliance'] ?? null, 'rate'),
            'fastest_delivery' => $this->mapFactor($insights['fastest_delivery'] ?? null, 'days'),
            'lowest_price' => $this->mapFactor($insights['lowest_price'] ?? null, 'price'),
        ];
    }

    private function mapFactor(?array $factor, string $valueKey): ?array
    {
        if (!$factor) {
            return null;
        }

        $value = $factor['value'] ?? null;

        $formattedValue = match ($valueKey) {
            'price' => $value !== null ? number_format((float) $value, 2, '.', '') : null,
            'days' => $value !== null ? number_format((float) $value, 1, '.', '') : null,
            'rate' => $value !== null ? number_format((float) $value, 2, '.', '') : null,
            default => $value,
        };

        return [
            'source_type' => $factor['source_type'] ?? $factor['type'] ?? null,
            'source_id' => $factor['source_id'] ?? null,
            'source_name' => $factor['source_name'] ?? null,
            $valueKey => $formattedValue,
        ];
    }
}
