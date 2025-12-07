<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = [
            'order_number' => $this->order_number,
            'order_type' => $this->order_type?->value,
            'order_type_label' => $this->order_type_label,
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            
            // Branch info
            'from' => [
                'branch_name' => $this->branch?->name,
                'branch_location' => $this->branch?->location,
            ],
            
            // Requested by
            'requested_by' => $this->requestedBy?->name ?? 'Me',
            'requested_date' => $this->created_at?->format('Y-m-d H:i:s'),
            
            // Message
            'message' => $this->message,
            
            // Financial summary
            'total_amount' => (float) $this->total_amount,
            'total_items' => $this->total_items,
            
            // Items list
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'item_logo' => $item->item_logo_url,
                    'quantity' => (float) $item->quantity_ordered,
                    'unit' => $item->unit_of_measurement,
                    'quality' => $item->quality_ordered?->value,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                ];
            }),
        ];

        // Add type-specific fields
        if ($this->order_type?->value === 'direct_supplier') {
            $data['supplier'] = $this->supplier ? new SupplierResource($this->supplier) : null;
            $data['contact_modes'] = $this->notification_channels ?? [];
        }

        if ($this->order_type?->value === 'via_purchasing_officer') {
            $data['processing_time'] = $this->processing_time?->value;
            $data['preferred_delivery_date'] = $this->preferred_delivery_date?->format('Y-m-d');
            $data['latest_delivery_date'] = $this->latest_delivery_date?->format('Y-m-d');
            $data['special_instructions'] = $this->special_instructions;
        }

        if ($this->order_type?->value === 'internal_transfer') {
            $data['priority'] = $this->priority?->value;
            $data['from_branch'] = $this->fromBranch ? [
                'id' => $this->fromBranch->id,
                'name' => $this->fromBranch->name,
                'location' => $this->fromBranch->location,
            ] : null;
            $data['transport_details'] = [
                'method' => $this->transport_method ?? 'Vehicle (Free)',
                'estimated_time' => $this->estimated_transport_hours,
                'driver' => $this->driver_name,
                'temperature' => $this->temperature,
            ];
        }

        return $data;
    }
}

