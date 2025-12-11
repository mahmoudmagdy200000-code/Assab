<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PendingOrderListResource extends JsonResource
{
    /**
     * Transform the resource into an array for list view.
     *
     * According to requirements 3.1.2.4.3:
     * - Number of items required
     * - Type
     * - Date and Time
     * - Status
     * - Supplier Name (for Direct Supplier Order only)
     */
    public function toArray($request): array
    {
        $data = [
            'id' => $this->id,
            'order_number' => $this->order_number,

            // Number of items required
            'number_of_items' => $this->total_items,

            // Type
            'type' => $this->order_type?->value,
            'type_label' => $this->order_type_label,

            // Date and Time (use submitted_at if available, otherwise created_at)
            'date_time' => $this->submitted_at?->format('Y-m-d H:i:s')
                ?? $this->created_at?->format('Y-m-d H:i:s'),
            'date' => $this->submitted_at?->format('Y-m-d')
                ?? $this->created_at?->format('Y-m-d'),
            'time' => $this->submitted_at?->format('H:i:s')
                ?? $this->created_at?->format('H:i:s'),

            // Status
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
        ];

        // Supplier Name (only for Direct Supplier Order)
        if ($this->order_type?->value === 'direct_supplier' && $this->relationLoaded('supplier')) {
            $data['supplier_name'] = $this->supplier?->name ?? null;
        }

        return $data;
    }
}
