<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderListResource extends JsonResource
{
    /**
     * Lightweight resource for order list (index) - only essential fields for performance
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'order_type' => $this->order_type?->value,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
