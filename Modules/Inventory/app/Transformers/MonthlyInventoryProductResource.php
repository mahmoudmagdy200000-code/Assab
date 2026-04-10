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
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'item_name' => $this->item_name,
            'item_logo' => $this->item?->logo_url,
            'unit' => $this->unit,
            'quantity_inventory' => (float) $this->quantity_inventory,
            'unit_price' => (float) $this->unit_price,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'count_method' => $this->count_method,
            'preferred_count_method' => $this->preferred_count_method,
            'count_metadata' => $this->count_metadata,
            'handled_by' => $this->handled_by_id !== null ? [
                'id' => $this->handled_by_id,
                'type' => $this->handled_by_type,
                'name' => $this->handledBy?->name ?? null,
            ] : null,
            'counted_by' => $this->counted_by_id !== null ? [
                'id' => $this->counted_by_id,
                'type' => $this->counted_by_type,
                'name' => $this->countedBy?->name ?? null,
            ] : null,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
