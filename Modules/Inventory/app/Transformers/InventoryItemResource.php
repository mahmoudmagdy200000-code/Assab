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
            'item' => [
                'id' => $this->item_id,
                'name' => $this->item_name,
                'code' => $this->item->code ?? null,
                'logo' => $this->item->logo ?? null,
                'unit' => $this->item->unit ?? null,
            ],
            'purchase_order_item' => [
                'id' => $this->purchase_order_item_id,
                'order_number' => $this->whenLoaded('purchaseOrderItem') && $this->purchaseOrderItem->purchaseOrder 
                    ? $this->purchaseOrderItem->purchaseOrder->order_number 
                    : null,
                'quantity_ordered' => $this->whenLoaded('purchaseOrderItem') 
                    ? (float) $this->purchaseOrderItem->quantity_ordered 
                    : null,
            ],
            'quantity_inventory' => (float) $this->quantity_inventory,
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
