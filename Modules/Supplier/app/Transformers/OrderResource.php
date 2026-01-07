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
                // For supplier view: if status is needs_approval_branch (supplier requested modification),
                // show it as needs_approval_supplier in the response (from supplier's perspective)
                $displayStatus = $item->status;
                $displayStatusValue = $item->status?->value ?? 'pending';
                $displayStatusLabel = $item->status?->label() ?? 'Pending';
                $displayStatusColor = $item->status?->color() ?? '#F59E0B';

                // If supplier requested modification (needs_approval_branch), show as needs_approval_supplier in supplier view
                if ($item->status === \Modules\Purchase\Enums\OrderItemStatus::NEEDS_APPROVAL_BRANCH && $item->approval_type) {
                    $displayStatusValue = 'needs_approval_supplier';
                    $displayStatusLabel = 'Needs Approval (Supplier)';
                    $displayStatusColor = '#F97316';
                }

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
                    'status' => $displayStatusValue,
                    'status_label' => $displayStatusLabel,
                    'status_color' => $displayStatusColor,
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

                // Add cancellation object only if cancelled by branch or supplier
                if ($item->status?->isCancelled()) {
                    $isCancelledByBranchOrSupplier = in_array($item->status, [
                        \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH,
                        \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_SUPPLIER,
                        \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION, // Also include modification cancellation
                    ]);

                    if ($isCancelledByBranchOrSupplier) {
                        $cancellationReason = $item->approval_data['cancellation_reason'] ?? null;
                        $cancelledAt = $item->updated_at?->format('Y-m-d H:i:s');
                        
                        // Determine who cancelled
                        $cancelledBy = null;
                        if ($item->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH || 
                            $item->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION) {
                            if ($this->relationLoaded('requestedBy') && $this->requestedBy) {
                                $cancelledBy = [
                                    'id' => $this->requestedBy->id,
                                    'name' => $this->requestedBy->name,
                                    'type' => 'branch_manager',
                                    'image' => $this->requestedBy->image_url ?? null,
                                ];
                            }
                        } elseif ($item->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_SUPPLIER) {
                            if ($this->relationLoaded('supplier') && $this->supplier) {
                                $cancelledBy = [
                                    'id' => $this->supplier->id,
                                    'name' => $this->supplier->name,
                                    'type' => 'supplier',
                                    'image' => $this->supplier->image_url ?? null,
                                ];
                            }
                        }

                        $itemData['cancellation'] = [
                            'reason' => $cancellationReason,
                            'cancelled_at' => $cancelledAt,
                            'cancelled_by' => $cancelledBy,
                        ];
                    } else {
                        // For other cancellation types (e.g., CANCELLED), set to null
                        $itemData['cancellation'] = null;
                    }
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
            'actual_delivery_at' => $this->actual_delivery_at?->toDateTimeString(),
            'driver_name' => $this->driver_name,
            'driver_contact' => $this->driver_contact,
            'driver_photo' => $this->driver_photo ? asset('storage/' . $this->driver_photo) : null,
            'vehicle_number' => $this->vehicle_number,
            'gps_tracking_url' => $this->gps_tracking_url,
            'delivery_route' => $this->delivery_route,
            'recipient_name' => $this->recipient_name,
            'delivery_photos' => $this->delivery_photos ? array_map(function ($photo) {
                return asset('storage/' . $photo);
            }, $this->delivery_photos) : null,
            'condition_confirmation' => $this->condition_confirmation,

            // Delivery Proof
            'delivery_proof' => $this->whenLoaded('deliveryProof', function () {
                return new DeliveryProofResource($this->deliveryProof);
            }),

            // Customer Feedback
            'feedbacks' => $this->whenLoaded('feedbacks', function () {
                return SupplierFeedbackResource::collection($this->feedbacks);
            }),

            // Additional Information
            'priority' => $this->priority?->value,
            'special_instructions' => $this->special_instructions,
            'message' => $this->message,
            'rejection_reason' => $this->rejection_reason,

            // Timestamps
            'created_at' => $this->created_at?->toDateTimeString(),
            'confirmed_at' => $this->confirmed_at?->toDateTimeString(),
            'preparation_started_at' => $this->preparation_started_at?->toDateTimeString(),
            'dispatched_at' => $this->dispatched_at?->toDateTimeString(),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
        ];
    }
}
