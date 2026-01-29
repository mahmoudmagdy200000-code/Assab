<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Transformers\BranchResource;
use Modules\BranchManagers\Transformers\BranchManagerResource;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Transformers\TimelineResource;

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
            'branch' => $this->whenLoaded('branch', function () {
                return $this->branch ? new BranchResource($this->branch) : null;
            }),

            // Branch Manager
            'branch_manager' => $this->whenLoaded('requestedBy', function () {
                return $this->requestedBy ? new BranchManagerResource($this->requestedBy) : null;
            }),

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
                        \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
                        \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_DELAYED,
                        \Modules\Purchase\Enums\OrderItemStatus::DELAYED_CANCELED,
                    ]);

                    if ($isCancelledByBranchOrSupplier) {
                        $cancellationReason = $item->approval_data['cancellation_reason'] ?? null;
                        $cancelledAt = $item->updated_at?->format('Y-m-d H:i:s');

                        // Determine who cancelled
                        $cancelledBy = null;
                        if (in_array($item->status, [
                            \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH,
                            \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
                            \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_DELAYED,
                            \Modules\Purchase\Enums\OrderItemStatus::DELAYED_CANCELED,
                        ])) {
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
                            'cancellation_reason' => $cancellationReason,
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
            'reason_for_rejected' => $this->getReasonForRejected(),
            'delay_details' => $this->getDelayDetails(),

            // Timestamps
            'created_at' => $this->created_at?->toDateTimeString(),
            'confirmed_at' => $this->confirmed_at?->toDateTimeString(),
            'preparation_started_at' => $this->preparation_started_at?->toDateTimeString(),
            'dispatched_at' => $this->dispatched_at?->toDateTimeString(),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),

            // Timelines
            'timelines' => $this->whenLoaded('timelines', fn() => TimelineResource::collection($this->timelines)),
        ];
    }

    /**
     * Get reason for rejected/cancelled order
     * Returns full object with cancellation/rejection details, null otherwise
     * Only returns cancellation_reason if cancelled by branch or supplier
     *
     * @return array|null
     */
    private function getReasonForRejected(): ?array
    {
        $status = $this->status;

        if (!$status) {
            return null;
        }

        // Check if order is cancelled by branch or supplier only
        if (in_array($status, [
            OrderStatus::CANCELLED_BY_BRANCH,
            OrderStatus::CANCELLED_BY_SUPPLIER,
        ])) {
            $cancelledBy = $this->getCancelledByInfo($status);

            return [
                'cancellation_reason' => $this->cancellation_reason ?? null,
                'cancelled_at' => $this->canceled_at?->format('Y-m-d H:i:s') ?? $this->rejected_at?->format('Y-m-d H:i:s'),
                'cancelled_by' => $cancelledBy,
            ];
        }

        // For generic CANCELED status (not by branch or supplier), return null
        if ($status === OrderStatus::CANCELED) {
            return null;
        }

        // Check if order is rejected
        if ($status === OrderStatus::REJECTED) {
            $rejectedBy = null;
            if ($this->relationLoaded('requestedBy') && $this->requestedBy) {
                $rejectedBy = [
                    'id' => $this->requestedBy->id ?? null,
                    'name' => $this->requestedBy->name ?? null,
                    'type' => 'branch_manager',
                    'image' => $this->requestedBy->image_url ?? null,
                ];
            }

            return [
                'rejection_reason' => $this->rejection_reason,
                'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
                'rejected_by' => $rejectedBy,
            ];
        }

        // For all other statuses, return null
        return null;
    }

    /**
     * Get information about who cancelled the order
     */
    private function getCancelledByInfo(OrderStatus $status): ?array
    {
        // Check if cancelled by supplier
        if ($status === OrderStatus::CANCELLED_BY_SUPPLIER) {
            if ($this->relationLoaded('supplier') && $this->supplier) {
                return [
                    'id' => $this->supplier->id ?? null,
                    'name' => $this->supplier->name ?? null,
                    'type' => 'supplier',
                    'image' => $this->supplier->image_url ?? null,
                ];
            }
        }

        // Check if cancelled by branch manager (CANCELLED_BY_BRANCH)
        if ($status === OrderStatus::CANCELLED_BY_BRANCH) {
            if ($this->relationLoaded('requestedBy') && $this->requestedBy) {
                return [
                    'id' => $this->requestedBy->id ?? null,
                    'name' => $this->requestedBy->name ?? null,
                    'type' => 'branch_manager',
                    'image' => $this->requestedBy->image_url ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Get delay details if order is delayed
     * Same structure as PurchaseHistoryDetailsResource for consistency.
     *
     * @return array|null
     */
    private function getDelayDetails(): ?array
    {
        if (!in_array($this->status, [
            OrderStatus::DELAYED,
            OrderStatus::DELAYED_CONFIRMED,
            OrderStatus::DELAYED_CANCELED,
        ])) {
            return null;
        }

        return [
            'delay_reason' => $this->getDelayReasonMessage(),
            'new_expected_delivery_date' => $this->expected_delivery_at?->format('Y-m-d') ?? null,
            'new_expected_delivery_time' => $this->expected_delivery_at?->format('H:i') ?? null,
            'delay_attachment' => $this->getDelayAttachment(),
        ];
    }

    /**
     * Get delay reason as message only (not JSON map).
     * If delay_reason is stored as JSON with "message" key, return that; otherwise return as-is.
     *
     * @return string|null
     */
    private function getDelayReasonMessage(): ?string
    {
        $reason = $this->delay_reason ?? null;
        if ($reason === null || $reason === '') {
            return null;
        }
        $decoded = json_decode($reason, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            return (string) $decoded['message'];
        }
        return $reason;
    }

    /**
     * Get delay attachment if exists (photo/other document related to delay)
     *
     * @return array|null
     */
    private function getDelayAttachment(): ?array
    {
        if (!$this->relationLoaded('documents')) {
            return null;
        }

        $delayDocument = $this->documents
            ->filter(function ($doc) {
                if (!in_array($doc->type, [DocumentType::PHOTO, DocumentType::OTHER])) {
                    return false;
                }
                $title = strtolower($doc->title ?? '');
                $description = strtolower($doc->description ?? '');
                $keywords = ['delay', 'delayed', 'تأخير'];
                foreach ($keywords as $keyword) {
                    if (str_contains($title, $keyword) || str_contains($description, $keyword)) {
                        return true;
                    }
                }
                return false;
            })
            ->first();

        if (!$delayDocument) {
            return null;
        }

        return [
            'id' => $delayDocument->id,
            'file_name' => $delayDocument->file_name ?? null,
            'original_name' => $delayDocument->original_name ?? null,
            'file_path' => $delayDocument->file_path ?? null,
            'file_url' => $delayDocument->file_url ?? null,
            'file_size' => $delayDocument->file_size ?? null,
            'formatted_size' => $delayDocument->formatted_size ?? null,
            'mime_type' => $delayDocument->mime_type ?? null,
            'type' => $delayDocument->type?->value ?? null,
            'type_label' => $delayDocument->type_label ?? null,
        ];
    }
}
