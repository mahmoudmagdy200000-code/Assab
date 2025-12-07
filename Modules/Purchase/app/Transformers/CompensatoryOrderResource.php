<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class CompensatoryOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            
            // Item details
            'item_name' => $this->item_name,
            'item_logo' => $this->item_logo_url,
            'quantity' => (float) $this->quantity,
            'quality' => $this->quality?->value,
            
            // Reorder details
            'reorder_supplier' => $this->whenLoaded('reorderSupplier', fn() => new SupplierResource($this->reorderSupplier)),
            'reorder_source' => $this->reorder_source,
            'delivery_urgency_deadline' => $this->delivery_urgency_deadline?->format('Y-m-d'),
            
            // Evidence
            'photo_evidence' => $this->photo_evidence,
            'additional_notes' => $this->additional_notes,
            
            // Flags
            'is_pending' => $this->is_pending,
            'is_completed' => $this->is_completed,
            
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}

