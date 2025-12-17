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
                'via_purchasing_officer' => $this->formatPurchasingOfficer($purchasingOfficer),
                'internal_transfer' => $internalTransfer,
            ],

            // Best option
            'best_option' => $this->resource['best_option'] ?? null,

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
    private function summarizeDirectSupplier(array $suppliers): ?array
    {
        if (empty($suppliers)) {
            return null;
        }

        $best = collect($suppliers)->sortBy('unit_price')->first();

        return [
            'price' => isset($best['unit_price'])
                ? number_format((float) $best['unit_price'], 2, '.', '')
                : null,
            'delivery_days' => $best['delivery_days'] ?? null,
            'rating' => isset($best['rating'])
                ? number_format((float) $best['rating'], 2, '.', '')
                : null,
        ];
    }

    /**
     * Ensure purchasing officer block has consistent formatting.
     */
    private function formatPurchasingOfficer(?array $purchasingOfficer): ?array
    {
        if (!$purchasingOfficer) {
            return null;
        }

        return [
            'unit_price' => isset($purchasingOfficer['unit_price'])
                ? number_format((float) $purchasingOfficer['unit_price'], 2, '.', '')
                : null,
            'delivery_days' => $purchasingOfficer['delivery_days'] ?? null,
            'rating' => isset($purchasingOfficer['rating'])
                ? number_format((float) $purchasingOfficer['rating'], 2, '.', '')
                : null,
            'processing_times' => $purchasingOfficer['processing_times'] ?? null,
            'order_count' => $purchasingOfficer['order_count'] ?? null,
        ];
    }
}
