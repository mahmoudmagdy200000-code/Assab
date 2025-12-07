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
            'item_logo' => $this->item_logo_url,
            'item_sku' => $this->item_sku,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            
            // Quantities
            'quantity_ordered' => (float) $this->quantity_ordered,
            'quantity_confirmed' => $this->quantity_confirmed ? (float) $this->quantity_confirmed : null,
            'quantity_received' => $this->quantity_received ? (float) $this->quantity_received : null,
            'unit_of_measurement' => $this->unit_of_measurement,
            
            // Pricing
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'discount' => (float) $this->discount,
            
            // Quality
            'quality_ordered' => $this->quality_ordered?->value,
            'quality_received' => $this->quality_received?->value,
            
            // Transfer details
            'available_in_source' => $this->available_in_source ? (float) $this->available_in_source : null,
            'remaining_balance' => $this->remaining_balance ? (float) $this->remaining_balance : null,
            'daily_consumption' => $this->daily_consumption ? (float) $this->daily_consumption : null,
            'weekend_forecast' => $this->weekend_forecast ? (float) $this->weekend_forecast : null,
            'next_supply_date' => $this->next_supply_date?->format('Y-m-d'),
            
            // Product info
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'temperature' => $this->temperature,
            'cooling_status' => $this->cooling_status,
            
            // Status
            'status' => $this->status,
            'quantity_variance' => $this->quantity_variance,
            'has_variance' => $this->has_variance,
            
            // Modifications
            'original_quantity' => $this->original_quantity ? (float) $this->original_quantity : null,
            'new_quantity' => $this->new_quantity ? (float) $this->new_quantity : null,
            'modification_note' => $this->modification_note,
            'is_alternative' => $this->is_alternative,
            'is_gift' => $this->is_gift,
        ];
    }
}

