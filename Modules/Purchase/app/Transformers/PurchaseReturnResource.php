<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'return_date' => $this->return_date?->format('Y-m-d'),
            'total_return_amount' => $this->total_return_amount,
            'required_action' => $this->required_action,
            'status' => $this->status,
            'additional_notes' => $this->additional_notes,
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            'escalated_to_brand_owner' => $this->escalated_to_brand_owner,
            'escalation_reason' => $this->escalation_reason,
            'resolution_type' => $this->resolution_type,
            'refund_amount' => $this->refund_amount,
            'refund_method' => $this->refund_method,
            'refund_note' => $this->refund_note,
            'refund_file' => $this->refund_file,
            'purchase_order' => $this->when($this->purchaseOrder, [
                'id' => $this->purchaseOrder?->id,
                'order_number' => $this->purchaseOrder?->order_number,
            ]),
            'supplier' => $this->when($this->supplier, [
                'id' => $this->supplier?->id,
                'name' => $this->supplier?->name,
            ]),
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
            'timeline' => PurchaseReturnTimelineResource::collection($this->whenLoaded('timeline')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
