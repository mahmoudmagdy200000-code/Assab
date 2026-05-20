<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        $supplierItem = $this->resource['supplier_item'];
        $branchItem = $this->resource['branch_item'];

        // Get item logo URL
        $itemLogo = null;
        if ($branchItem && $branchItem->item_logo) {
            if (is_array($branchItem->item_logo)) {
                $logo = $branchItem->item_logo[0] ?? null;
            } else {
                $logo = $branchItem->item_logo;
            }

            if ($logo) {
                $itemLogo = str_starts_with($logo, 'http')
                    ? $logo
                    : asset('storage/'.$logo);
            }
        }

        return [
            // Item Information
            // Use item_id from BranchItem (which references Item.id) to match SupplierProduct.item_id
            'item_id' => $branchItem?->item_id ?? $supplierItem->item_id,
            'item_name' => $branchItem?->item_name,
            'item_logo' => $itemLogo,
            'item_code' => $branchItem?->item_code,
            'item_unit' => $branchItem?->item_unit ?? 'kg',
            'item_price' => $branchItem?->item_price ? (float) $branchItem->item_price : null,
            'category' => $branchItem?->category,
            'subcategory' => $branchItem?->subcategory,

            // Supplier Item Details
            'supplier_item_id' => $supplierItem->id,
            'quantity' => 1.0, // Default quantity, editable
            'total_amount' => round((float) $supplierItem->unit_price, 2),

            // Pricing
            'price_rate' => round((float) $supplierItem->unit_price, 2),
            'economy_price' => $supplierItem->economy_price ? round((float) $supplierItem->economy_price, 2) : null,
            'standard_price' => $supplierItem->standard_price ? round((float) $supplierItem->standard_price, 2) : null,
            'premium_price' => $supplierItem->premium_price ? round((float) $supplierItem->premium_price, 2) : null,

            // Availability
            'is_available' => $supplierItem->is_available,
            'min_order_quantity' => $supplierItem->min_order_quantity ? (float) $supplierItem->min_order_quantity : null,
            'max_order_quantity' => $supplierItem->max_order_quantity ? (float) $supplierItem->max_order_quantity : null,

            // Delivery
            'delivery_hours' => $supplierItem->delivery_hours,
            'delivery_days' => $supplierItem->delivery_hours ? round($supplierItem->delivery_hours / 24, 1) : null,

            // Rating
            'rating' => $supplierItem->rating ? round((float) $supplierItem->rating, 1) : null,
        ];
    }
}
