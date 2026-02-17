<?php

namespace Modules\RecurringOrder\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name ?? $this->item?->name,
            'item_image' => $this->item_logo_url ?? $this->item?->logo_url,
            'item_logo' => $this->item_logo_url ?? $this->item?->logo_url,
            'quantity' => (float) $this->quantity,
            'quality' => $this->quality ?? 'standard',
            'unit_price' => (float) $this->unit_price,
            'preferred_delivery_date' => $this->preferred_delivery_date?->format('Y-m-d'),
            'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d'),
            'special_instructions' => $this->special_instructions,
        ];
    }
}
