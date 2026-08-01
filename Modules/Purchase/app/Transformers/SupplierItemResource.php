<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the supplier's catalog as the mobile «choose products» list shows
 * it. Accepts `['supplier_item' => …, 'branch_item' => …, 'item' => …]`:
 * branch_item is OPTIONAL — a supplier may sell an item this branch does not
 * stock yet, and dropping those rows is what made a stocked supplier look
 * empty in the app.
 */
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
        $branchItem = $this->resource['branch_item'] ?? null;
        $item = $this->resource['item'] ?? $branchItem?->item;

        // Get item logo URL
        $itemLogo = null;
        $logo = $branchItem?->item_logo ?? $item?->logo;

        if ($logo) {
            if (is_array($logo)) {
                $logo = $logo[0] ?? null;
            }

            if ($logo) {
                $itemLogo = str_starts_with($logo, 'http')
                    ? $logo
                    : asset('storage/'.$logo);
            }
        }

        // The branch price is the reference price when the branch stocks the
        // item; otherwise the supplier's own price stands in, so the app never
        // has to render a priceless row.
        $itemPrice = $branchItem?->item_price ?? $supplierItem->unit_price;

        return [
            // Item Information
            // Use item_id from BranchItem (which references Item.id) to match SupplierProduct.item_id
            'item_id' => $branchItem?->item_id ?? $supplierItem->item_id,
            // Nullable strings the app casts to non-null String — coalesce to ''
            // so a bridge-seeded item (no code/subcategory) can't crash the app.
            'item_name' => $branchItem?->item_name ?? $item?->name ?? '',
            'item_logo' => $itemLogo,
            'item_code' => $branchItem?->item_code ?? $item?->code ?? '',
            'item_unit' => $branchItem?->item_unit ?? $item?->unit ?? 'kg',
            // Numeric fields are coalesced for the same reason the strings are:
            // the app casts them with `as num` and a null is a hard crash
            // («type 'Null' is not a subtype of type 'num' in type cast»).
            'item_price' => round((float) $itemPrice, 2),
            'category' => $branchItem?->category ?? $item?->category ?? '',
            'subcategory' => $branchItem?->subcategory ?? $item?->subcategory ?? '',
            // Whether this branch already stocks the item (the row is orderable
            // either way) — lets the app badge «new to this branch».
            'in_branch_catalog' => $branchItem !== null,

            // Supplier Item Details
            'supplier_item_id' => $supplierItem->id,
            'quantity' => 1.0, // Default quantity, editable
            'total_amount' => round((float) $supplierItem->unit_price, 2),

            // Pricing
            'price_rate' => round((float) $supplierItem->unit_price, 2),
            'economy_price' => round((float) ($supplierItem->economy_price ?? $supplierItem->unit_price), 2),
            'standard_price' => round((float) ($supplierItem->standard_price ?? $supplierItem->unit_price), 2),
            'premium_price' => round((float) ($supplierItem->premium_price ?? $supplierItem->unit_price), 2),

            // Availability
            'is_available' => (bool) $supplierItem->is_available,
            'min_order_quantity' => (float) ($supplierItem->min_order_quantity ?? 0),
            'max_order_quantity' => (float) ($supplierItem->max_order_quantity ?? 0),

            // Delivery
            'delivery_hours' => (int) ($supplierItem->delivery_hours ?? 0),
            'delivery_days' => $supplierItem->delivery_hours
                ? round($supplierItem->delivery_hours / 24, 1)
                : 0.0,

            // Rating
            'rating' => round((float) ($supplierItem->rating ?? 0), 1),
        ];
    }
}
