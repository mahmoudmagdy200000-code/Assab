<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ReturnOrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_name' => $this->item_name,
            'item_logo' => $this->item_logo_url,
            
            // Quantity
            'return_quantity' => (float) $this->return_quantity,
            'unit_of_measurement' => $this->unit_of_measurement,
            
            // Quality
            'quality_reason' => $this->quality_reason?->value,
            'quality_reason_label' => $this->quality_reason_label,
            
            // Pricing
            'unit_price' => (float) $this->unit_price,
            'return_amount' => (float) $this->return_amount,
            
            // Files
            'files' => $this->files ? array_map(function ($file) {
                return str_starts_with($file, 'http') 
                    ? $file 
                    : asset('storage/' . $file);
            }, $this->files) : [],
            'file_count' => count($this->files ?? []),
            'notes' => $this->notes,
        ];
    }
}

