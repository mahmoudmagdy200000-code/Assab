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
                'internal_transfer' => $this->formatInternalTransfers($internalTransfer),
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
    private function summarizeDirectSupplier(array $suppliers): array
    {
        $best = empty($suppliers)
            ? null
            : collect($suppliers)->sortBy('unit_price')->first();

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
            'delivery_days' => isset($purchasingOfficer['delivery_days'])
                ? number_format((float) $purchasingOfficer['delivery_days'], 1, '.', '')
                : null,
            'rating' => isset($purchasingOfficer['rating'])
                ? number_format((float) $purchasingOfficer['rating'], 2, '.', '')
                : null,
            'processing_times' => $purchasingOfficer['processing_times'] ?? null,
            'order_count' => $purchasingOfficer['order_count'] ?? null,
        ];
    }

    /**
     * Format internal transfer options with consistent numeric formatting.
     */
    private function formatInternalTransfers(array $options): array
    {
        if (empty($options)) {
            return [];
        }

        return collect($options)->map(function (array $option) {
            return [
                'branch_id' => $option['branch_id'] ?? null,
                'branch_name' => $option['branch_name'] ?? null,
                'branch_image' => $option['branch_image'] ?? null,
                'available_quantity' => $option['available_quantity'] ?? null,
                'availability_percentage' => $option['availability_percentage'] ?? null,
                'quality' => $option['quality'] ?? null,
                'expiry_date' => $option['expiry_date'] ?? null,
                'cooling_status' => $option['cooling_status'] ?? null,
                'last_update' => $option['last_update'] ?? null,
                'unit_price' => isset($option['unit_price'])
                    ? number_format((float) $option['unit_price'], 2, '.', '')
                    : null,
                'total_price' => isset($option['total_price'])
                    ? number_format((float) $option['total_price'], 2, '.', '')
                    : null,
                'rating' => isset($option['rating'])
                    ? number_format((float) $option['rating'], 2, '.', '')
                    : null,
                'response_rate' => isset($option['response_rate'])
                    ? number_format((float) $option['response_rate'], 2, '.', '')
                    : null,
                'distance' => isset($option['distance'])
                    ? number_format((float) $option['distance'], 2, '.', '')
                    : null,
            ];
        })->all();
    }
}
