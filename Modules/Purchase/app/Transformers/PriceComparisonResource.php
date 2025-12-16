<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PriceComparisonResource extends JsonResource
{
    public function toArray($request): array
    {
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
                'direct_supplier' => $this->resource['sources']['direct_supplier'] ?? [],
                'via_purchasing_officer' => $this->resource['sources']['via_purchasing_officer'] ?? null,
                'internal_transfer' => $this->resource['sources']['internal_transfer'] ?? [],
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
}

