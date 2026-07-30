<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            // Uploaded catalog rows carry NULL code/unit (and `logo` is an array
            // cast) — the app casts these to String, so coalesce and use logo_url.
            'item' => [
                'id' => $this->item_id,
                'name' => $this->item_name ?? $this->item?->name ?? '',
                'code' => $this->item?->code ?? '',
                'logo' => $this->item?->logo_url ?? '',
                'unit' => $this->item?->unit ?? 'kg',
            ],
            'purchase_order_item' => [
                'id' => $this->purchase_order_item_id,
                'order_number' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem?->purchaseOrder?->order_number),
                'quantity_ordered' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem !== null ? (float) $this->purchaseOrderItem->quantity_ordered : null),
            ],
            'quantity_inventory' => (float) $this->quantity_inventory,
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
