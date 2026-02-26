<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryProductResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $handledBy = $this->handledBy;
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'item_name' => $this->item_name,
            'unit' => $this->unit,
            'quantity_inventory' => (float) $this->quantity_inventory,
            'unit_price' => (float) $this->unit_price,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'count_method' => $this->count_method,
            'preferred_count_method' => $this->preferred_count_method,
            'count_metadata' => $this->count_metadata,
            'handled_by' => $this->when($this->handled_by_id, [
                'id' => $this->handled_by_id,
                'type' => $this->handled_by_type,
                'name' => $handledBy?->name ?? null,
            ]),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
