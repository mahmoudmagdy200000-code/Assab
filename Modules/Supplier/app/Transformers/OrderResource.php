<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Transformers\BranchResource;
use Modules\BranchManagers\Transformers\BranchManagerResource;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Transformers\FileResource as PurchaseFileResource;
use Modules\Purchase\Transformers\TimelineResource;
use Carbon\Carbon;

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

        // Delay rejected by branch (same structure as cancellation)
        if ($status === OrderStatus::DELAYED_CANCELED) {
            $cancelledBy = $this->getDelayBranchManagerForRejection();
            return [
                'cancellation_reason' => $this->cancellation_reason ?? null,
                'cancelled_at' => $this->canceled_at?->format('Y-m-d\TH:i:s\Z'),
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

        // Check if cancelled by branch manager (CANCELLED_BY_BRANCH or DELAYED_CANCELED)
        if (in_array($status, [OrderStatus::CANCELLED_BY_BRANCH, OrderStatus::DELAYED_CANCELED])) {
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
     * Branch manager who rejected the delay (for reason_for_rejected when status is DELAYED_CANCELED).
     */
    private function getDelayBranchManagerForRejection(): ?array
    {
        $branchManager = $this->getDelayBranchManager();
        if ($branchManager) {
            $branchManager['type'] = 'branch_manager';
            return $branchManager;
        }
        return $this->getCancelledByInfo(OrderStatus::DELAYED_CANCELED);
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
            'new_expected_delivery_time' => $this->expected_delivery_at?->format('h:i A') ?? null,
            'delay_attachment' => $this->getDelayAttachment(),
            'delay_reported_at' => $this->getDelayReportedAt(),
            'delay_approved_at' => $this->getDelayApprovedAt(),
            'delay_rejected_at' => $this->getDelayRejectedAt(),
            'branch_manager' => $this->getDelayBranchManager(),
        ];
    }

    /**
     * When did supplier report the delay (from first DELIVERY_DELAYED timeline event).
     */
    private function getDelayReportedAt(): ?string
    {
        if (!$this->relationLoaded('timelines')) {
            return null;
        }
        $event = $this->timelines
            ->where('event_type', TimelineEventType::DELIVERY_DELAYED)
            ->sortBy('occurred_at')
            ->first();
        return $event?->occurred_at?->format('Y-m-d H:i:s');
    }

    /**
     * When did branch manager approve the delay.
     */
    private function getDelayApprovedAt(): ?string
    {
        if (!$this->relationLoaded('timelines')) {
            return null;
        }
        $event = $this->timelines
            ->filter(fn($t) => $t->event_type === TimelineEventType::APPROVAL_GRANTED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortBy('occurred_at')
            ->first();
        return $event?->occurred_at?->format('Y-m-d H:i:s');
    }

    /**
     * When did branch manager reject the delay.
     */
    private function getDelayRejectedAt(): ?string
    {
        if (!$this->relationLoaded('timelines')) {
            return null;
        }
        $event = $this->timelines
            ->filter(fn($t) => $t->event_type === TimelineEventType::APPROVAL_DENIED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortBy('occurred_at')
            ->first();
        return $event?->occurred_at?->format('Y-m-d H:i:s');
    }

    /**
     * Branch manager who approved or rejected the delay (from timeline actor).
     */
    private function getDelayBranchManager(): ?array
    {
        if (!$this->relationLoaded('timelines')) {
            return $this->getDelayBranchManagerFromRequestedBy();
        }
        $approveEvent = $this->timelines
            ->filter(fn($t) => $t->event_type === TimelineEventType::APPROVAL_GRANTED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortByDesc('occurred_at')
            ->first();
        $rejectEvent = $this->timelines
            ->filter(fn($t) => $t->event_type === TimelineEventType::APPROVAL_DENIED
                && ($t->metadata['approval_type'] ?? null) === 'delay')
            ->sortByDesc('occurred_at')
            ->first();
        $event = $rejectEvent ?? $approveEvent;
        if ($event && $event->actor_id && $event->actor_name) {
            return [
                'id' => $event->actor_id,
                'name' => $event->actor_name,
                'image' => $event->actor_image_url ?? null,
            ];
        }
        return $this->getDelayBranchManagerFromRequestedBy();
    }

    /**
     * Fallback: branch manager from order requestedBy.
     */
    private function getDelayBranchManagerFromRequestedBy(): ?array
    {
        if (!$this->relationLoaded('requestedBy') || !$this->requestedBy) {
            return null;
        }
        return [
            'id' => $this->requestedBy->id,
            'name' => $this->requestedBy->name,
            'image' => $this->requestedBy->image_url ?? null,
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
     * Get delay attachment if exists.
     * Uses FileResource structure (id, file_name, file_type, file_size, url, uploaded_at).
     * First checks delay_reason JSON for photo path; otherwise delay-related documents.
     * No null values: strings default to '', file_size to 0.
     *
     * @return array|null
     */
    private function getDelayAttachment(): ?array
    {
        $reason = $this->delay_reason ?? null;
        if (is_string($reason)) {
            $decoded = json_decode($reason, true);
            if (is_array($decoded) && !empty($decoded['photo'])) {
                $photoPath = $decoded['photo'];
                $fileResource = PurchaseFileResource::make($photoPath)->toArray(request());
                return $this->fileResourceWithoutNulls($fileResource);
            }
        }

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

        $fileResource = PurchaseFileResource::make($delayDocument)->toArray(request());
        return $this->fileResourceWithoutNulls($fileResource);
    }

    /**
     * Ensure FileResource-shaped array has no null values.
     *
     * @param array<string, mixed> $arr
     * @return array<string, mixed>
     */
    private function fileResourceWithoutNulls(array $arr): array
    {
        return [
            'id' => $arr['id'] ?? '',
            'file_name' => $arr['file_name'] ?? '',
            'file_type' => $arr['file_type'] ?? '',
            'file_size' => isset($arr['file_size']) ? (int) $arr['file_size'] : 0,
            'url' => $arr['url'] ?? '',
            'uploaded_at' => $arr['uploaded_at'] ?? Carbon::now()->format('Y-m-d H:i:s'),
        ];
    }
}
