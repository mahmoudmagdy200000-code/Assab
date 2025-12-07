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
            'item_logo' => $this->item_logo_url,
            'unit_of_measurement' => $this->unit_of_measurement,
            
            // Quantities
            'quantity_ordered' => (float) $this->quantity_ordered,
            'quantity_received' => (float) $this->quantity_received,
            'quantity_variance' => (float) $this->quantity_variance,
            
            // Quality
            'quality_ordered' => $this->quality_ordered?->value,
            'quality_received' => $this->quality_received?->value,
            'has_quality_variance' => $this->has_quality_variance,
            
            // Inspection
            'temperature' => $this->temperature,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'photo' => $this->photo_url,
            'notes' => $this->notes,
            
            // Pricing
            'unit_price' => (float) $this->unit_price,
            'expected_total' => (float) $this->expected_total,
            'received_total' => (float) $this->received_total,
            'variance_amount' => (float) $this->variance_amount,
            
            // Variance
            'variance_type' => $this->variance_type?->value,
            'has_variance' => $this->has_variance,
            
            // Unlisted items
            'is_unlisted' => $this->is_unlisted,
            'unlisted_reason' => $this->unlisted_reason,
        ];
    }
}

