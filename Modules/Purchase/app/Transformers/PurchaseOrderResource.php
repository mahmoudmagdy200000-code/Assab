<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Transformers\BranchResource;
use Modules\BranchManagers\Transformers\BranchManagerResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type?->value,
            'order_type_label' => $this->order_type_label,
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'priority' => $this->priority?->value,
            'quality_level' => $this->quality_level?->value,

            // Branch info
            'branch' => $this->whenLoaded('branch', function () {
                return $this->branch ? new BranchResource($this->branch) : null;
            }),

            // Supplier info
            'supplier' => $this->whenLoaded('supplier', fn() => new SupplierResource($this->supplier)),

            // Source branch (for transfers)
            'from_branch' => $this->whenLoaded('fromBranch', function () {
                return $this->fromBranch ? new BranchResource($this->fromBranch) : null;
            }),

            // Requested by
            'requested_by' => $this->whenLoaded('requestedBy', function () {
                return $this->requestedBy ? new BranchManagerResource($this->requestedBy) : null;
            }),

            // Items summary
            'total_items' => $this->total_items,
            'received_items' => $this->received_items,

            // Financial
            'subtotal' => (float) $this->subtotal,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,

            // Delivery info
            'preferred_delivery_date' => $this->preferred_delivery_date?->format('Y-m-d'),
            'latest_delivery_date' => $this->latest_delivery_date?->format('Y-m-d'),
            'expected_delivery_at' => $this->expected_delivery_at?->format('Y-m-d H:i:s'),
            'actual_delivery_at' => $this->actual_delivery_at?->format('Y-m-d H:i:s'),

            // Transfer details
            'transport_method' => $this->transport_method,
            'driver_name' => $this->driver_name,
            'vehicle_number' => $this->vehicle_number,

            // Messages
            'message' => $this->message,
            'special_instructions' => $this->special_instructions,

            // Timestamps
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i:s'),
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

            // Rejection details (for Internal Transfer)
            'rejection_reason' => $this->when($this->status?->value === 'rejected', $this->rejection_reason),
            'rejected_by' => $this->when(
                $this->status?->value === 'rejected' && $this->relationLoaded('requestedBy') && $this->requestedBy,
                fn() => [
                    'id' => $this->requestedBy->id,
                    'name' => $this->requestedBy->name,
                    'image' => $this->requestedBy->image_url ?? null,
                ]
            ),

            // Status details with dates (for Internal Transfer)
            'status_details' => $this->when(
                $this->order_type?->isTransfer(),
                function () {
                    $statusValue = $this->status?->value;
                    $statusDate = match($statusValue) {
                        'confirmed' => $this->confirmed_at?->format('Y-m-d H:i:s'),
                        'partial_confirmation' => $this->confirmed_at?->format('Y-m-d H:i:s'),
                        'rejected' => $this->rejected_at?->format('Y-m-d H:i:s'),
                        default => $this->created_at?->format('Y-m-d H:i:s'),
                    };

                    return [
                        'status' => $this->status_label,
                        'status_date' => $statusDate,
                        'is_full_approved' => $statusValue === 'confirmed',
                        'is_partial_approved' => $statusValue === 'partial_confirmation',
                        'is_rejected' => $statusValue === 'rejected',
                    ];
                }
            ),

            // Flags
            'can_receive' => $this->can_receive,
            'is_active' => $this->is_active,

            // Related data
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'timelines' => TimelineResource::collection($this->whenLoaded('timelines')),
        ];
    }
}

