<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image ? asset('storage/' . $this->image) : null,
            'sku' => $this->sku,
            'unit_price' => (float) $this->unit_price,
            'economy_price' => $this->economy_price ? (float) $this->economy_price : null,
            'standard_price' => $this->standard_price ? (float) $this->standard_price : null,
            'premium_price' => $this->premium_price ? (float) $this->premium_price : null,
            'is_available' => $this->is_available,
            'min_order_quantity' => $this->min_order_quantity ? (float) $this->min_order_quantity : null,
            'max_order_quantity' => $this->max_order_quantity ? (float) $this->max_order_quantity : null,
            'stock_quantity' => (float) $this->stock_quantity,
            'delivery_hours' => $this->delivery_hours,
            'quality_level' => $this->quality_level,
            'rating' => $this->rating ? (float) $this->rating : null,
            'specifications' => $this->specifications ?? [],
            'images' => $this->images ?? [],
            'categories' => $this->categories ?? [],
            'inventory' => $this->whenLoaded('inventory', function () {
                // inventory is HasMany, so get the first record or sum quantities
                $inventory = $this->inventory->first();
                if (!$inventory) {
                    return null;
                }
                return [
                    'quantity' => (float) $inventory->quantity,
                    'reserved_quantity' => (float) $inventory->reserved_quantity,
                    'available_quantity' => (float) $inventory->available_quantity,
                    'reorder_level' => $inventory->reorder_level ? (float) $inventory->reorder_level : null,
                    'is_low_stock' => $inventory->isLowStock(),
                ];
            }),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}

