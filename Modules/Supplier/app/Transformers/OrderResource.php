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
                $itemData = [
                    'id' => $item->id,
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name,
                    'item_logo' => $item->item_logo,
                    'item_unit' => $item->unit_of_measurement,
                    'quantity_ordered' => (float) $item->quantity_ordered,
                    'quantity_confirmed' => $item->quantity_confirmed ? (float) $item->quantity_confirmed : null,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'quality_level' => $item->quality_ordered?->value,
                    'status' => $item->status?->value ?? 'pending',
                    'status_label' => $item->status?->label() ?? 'Pending',
                    'status_color' => $item->status?->color() ?? '#F59E0B',
                ];

                // Add approval information if item needs approval or has approval data
                if ($item->status?->needsApproval() || $item->approval_type) {
                    $itemData['approval_type'] = $item->approval_type;
                    $itemData['approval_data'] = $item->approval_data;
                    // For supplier view: can_approve/can_reject are false (only branch manager can approve/reject)
                    // But supplier can see their submitted requests
                    $itemData['can_approve'] = false;
                    $itemData['can_reject'] = false;
                } else {
                    $itemData['can_approve'] = false;
                    $itemData['can_reject'] = false;
                }

                return $itemData;
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
