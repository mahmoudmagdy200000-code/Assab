<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type?->value,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            // Branch Information
            'branch' => [
                'id' => $this->branch->id ?? null,
                'name' => $this->branch->name ?? null,
                'location' => $this->branch->location ?? null,
                'address' => $this->branch->address ?? null,
            ],

            // Branch Manager
            'branch_manager' => [
                'id' => $this->requestedBy->id ?? null,
                'name' => $this->requestedBy->name ?? null,
                'email' => $this->requestedBy->email ?? null,
                'phone' => $this->requestedBy->phone ?? null,
            ],

            // Order Items
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name,
                    'item_logo' => $item->item_logo,
                    'item_unit' => $item->unit,
                    'quantity_ordered' => (float) $item->quantity_ordered,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'quality_level' => $item->quality_level?->value,
                ];
            }),

            // Financial Information
            'subtotal' => (float) ($this->subtotal ?? 0),
            'tax_amount' => (float) ($this->tax_amount ?? 0),
            'total_amount' => (float) ($this->total_amount ?? 0),

            // Delivery Information
            'preferred_delivery_date' => $this->preferred_delivery_date?->toDateString(),
            'expected_delivery_at' => $this->expected_delivery_at?->toDateTimeString(),

            // Additional Information
            'priority' => $this->priority?->value,
            'special_instructions' => $this->special_instructions,
            'message' => $this->message,
            'rejection_reason' => $this->rejection_reason,

            // Timestamps
            'created_at' => $this->created_at?->toDateTimeString(),
            'confirmed_at' => $this->confirmed_at?->toDateTimeString(),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
        ];
    }
}

