<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'quality' => $this->quality,
            'rate' => $this->rate,
            'total_price' => $this->total_price,
            'status' => $this->status,
            'requested_quantity' => $this->requested_quantity,
            'confirmed_quantity' => $this->confirmed_quantity,
            'received_quantity' => $this->received_quantity,
            'variance_quantity' => $this->variance_quantity,
            'variance_type' => $this->variance_type,
            'rejection_reason' => $this->rejection_reason,
            'modification_note' => $this->modification_note,
        ];
    }
}
