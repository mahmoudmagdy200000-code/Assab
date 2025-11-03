<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'total_amount' => $this->total_amount,
            'total_items' => $this->total_items,
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d'),
            'special_instructions' => $this->special_instructions,
            'message' => $this->message,
            'notification_methods' => $this->notification_methods,
            'requested_date' => $this->requested_date?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'canceled_at' => $this->canceled_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejection_reason,
            'branch' => [
                'id' => $this->branch?->id,
                'name' => $this->branch?->name,
                'location' => $this->branch?->location,
            ],
            'branch_manager' => [
                'id' => $this->branchManager?->id,
                'name' => $this->branchManager?->name,
                'email' => $this->branchManager?->email,
            ],
            'supplier' => $this->when($this->supplier, [
                'id' => $this->supplier?->id,
                'name' => $this->supplier?->name,
                'status' => $this->supplier?->status,
            ]),
            'purchasing_officer' => $this->when($this->purchasingOfficer, [
                'id' => $this->purchasingOfficer?->id,
                'name' => $this->purchasingOfficer?->name,
            ]),
            'transfer_from_branch' => $this->when($this->transferFromBranch, [
                'id' => $this->transferFromBranch?->id,
                'name' => $this->transferFromBranch?->name,
            ]),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'timeline' => PurchaseTimelineResource::collection($this->whenLoaded('timeline')),
            'modifications' => PurchaseModificationResource::collection($this->whenLoaded('modifications')),
            'tracking' => PurchaseTrackingResource::collection($this->whenLoaded('trackingUpdates')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
