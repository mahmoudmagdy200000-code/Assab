<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderListResource extends JsonResource
{
    protected ?string $orderRequestType = null;

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @param  string|null  $orderRequestType  'order' or 'request'
     * @return void
     */
    public function __construct($resource, ?string $orderRequestType = null)
    {
        parent::__construct($resource);
        $this->orderRequestType = $orderRequestType;
    }

    /**
     * Transform the resource into an array for order list (index)
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

            // branch name
            'branch_name' => $this->branch?->name,
            'branch_id' => $this->branch?->id,

            // Status
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
        ];

        // Source name based on order type
        $sourceName = null;
        if ($this->order_type?->value === 'internal_transfer' && $this->relationLoaded('fromBranch')) {
            $sourceName = $this->fromBranch?->name;
        } elseif ($this->order_type?->value === 'direct_supplier' && $this->relationLoaded('supplier')) {
            $sourceName = $this->supplier?->name;
        } elseif ($this->order_type?->value === 'via_purchasing_officer') {
            $sourceName = 'Purchasing Officer';
        }

        if ($sourceName) {
            $data['source_name'] = $sourceName;
        }

        // Request type: order or request (passed from controller)
        if ($this->orderRequestType) {
            $data['request_type'] = $this->orderRequestType;
        }

        // Supplier Name (only for Direct Supplier Order) - keep for backward compatibility
        if ($this->order_type?->value === 'direct_supplier' && $this->relationLoaded('supplier')) {
            $data['supplier_name'] = $this->supplier?->name ?? null;
        }

        return $data;
    }
}
