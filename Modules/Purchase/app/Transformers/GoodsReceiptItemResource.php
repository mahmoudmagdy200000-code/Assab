<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'quantity_ordered' => $this->quantity_ordered,
            'quantity_received' => $this->quantity_received,
            'unit' => $this->unit,
            'quality' => $this->quality,
            'temperature' => $this->temperature,
            'expiration_date' => $this->expiration_date?->format('Y-m-d'),
            'photo' => $this->photo,
            'notes' => $this->notes,
            'is_gift' => $this->is_gift,
            'price_per_unit' => $this->price_per_unit,
            'reason_for_addition' => $this->reason_for_addition,
            'has_variance' => $this->hasVariance(),
            'variance_quantity' => $this->getVarianceQuantity(),
        ];
    }
}
