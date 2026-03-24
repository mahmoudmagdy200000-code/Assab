<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchasingOfficerItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        $item = $this->resource['item'];
        $priceComparison = $this->resource['price_comparison'];

        // Get item logo URL
        $itemLogo = null;
        if ($item->item_logo) {
            if (is_array($item->item_logo)) {
                $logo = $item->item_logo[0] ?? null;
            } else {
                $logo = $item->item_logo;
            }

            if ($logo) {
                $itemLogo = str_starts_with($logo, 'http')
                    ? $logo
                    : asset('storage/' . $logo);
            }
        }

        return [
            // Item Information
            'item_id' => $item->item_id, // Use item_id from BranchItem, not id
            // 'item_name' => $item->item_name ?? $item->item?->name,
            'item_code' => $item->item_code ?? $item->item?->code,
            'item_unit' => $item->item_unit ?? 'kg',
            'item_logo' => $itemLogo,
            'item_price' => (float) ($this->resource['resolved_item_price'] ?? $item->item_price ?? 0),

            // Editable Fields
            'quantity' => $this->resource['quantity'] ?? 1.0, // Editable
            'quality' => $this->resource['quality'] ?? 'standard', // Editable

            // Price Comparison
            'price_comparison' => [
                'direct_supplier' => [
                    'unit_price' => $priceComparison['direct_supplier']['unit_price'] ?? 0,
                    'total_amount' => $priceComparison['direct_supplier']['total_amount'] ?? 0,
                ],
                'purchasing_officer' => [
                    'unit_price' => $priceComparison['purchasing_officer']['unit_price'] ?? 0,
                    'total_amount' => $priceComparison['purchasing_officer']['total_amount'] ?? 0,
                ],
                'savings' => $priceComparison['savings'] ?? 0,
            ],
        ];
    }
}
