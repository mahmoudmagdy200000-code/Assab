<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Support\PurchaseFileHelper;
use Modules\Purchase\Transformers\ReturnOrderItemResource;
use Modules\Purchase\Transformers\TimelineResource;

/**
 * Return details for Supplier app: branch + timelines (same style as Purchase details).
 */
class ReturnDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $responseFiles = $this->response_files ?? [];
        $responseFilesList = is_array($responseFiles)
            ? array_map(fn ($f) => PurchaseFileHelper::toApiShape($f), $responseFiles)
            : [];

        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'return_date' => $this->return_date?->format('Y-m-d'),

            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,

            'required_action' => $this->required_action?->value,
            'required_action_label' => $this->required_action_label,

            'total_return_amount' => (float) $this->total_return_amount,
            'refund_amount' => $this->refund_amount ? (float) $this->refund_amount : null,
            'refund_method' => $this->refund_method,

            'additional_notes' => $this->additional_notes,
            'response_notes' => $this->response_notes,
            'response_files' => $responseFilesList,
            'responded_at' => $this->responded_at?->format('Y-m-d H:i:s'),

            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),

            // Cancellation (for rejected returns)
            'cancellation' => $this->getCancellationDetails(),

            'is_escalated' => $this->is_escalated,
            'escalation_reason' => $this->escalation_reason,
            'escalated_at' => $this->escalated_at?->format('Y-m-d H:i:s'),

            'resolution_type' => $this->resolution_type,
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i:s'),

            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),

            'is_draft' => $this->is_draft,
            'is_pending' => $this->is_pending,
            'is_completed' => $this->is_completed,

            'purchase_order' => $this->whenLoaded('purchaseOrder', function () {
                if (!$this->purchaseOrder) {
                    return null;
                }
                return [
                    'id' => $this->purchaseOrder->id,
                    'order_number' => $this->purchaseOrder->order_number,
                    'status' => $this->purchaseOrder->status?->value,
                    'status_label' => $this->purchaseOrder->status?->label(),
                ];
            }),

            'branch' => $this->whenLoaded('branch', function () {
                if (!$this->branch) {
                    return null;
                }
                return [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                    'lat' => $this->branch->lat ? (float) $this->branch->lat : null,
                    'lng' => $this->branch->lng ? (float) $this->branch->lng : null,
                    'opening_hours' => $this->branch->opening_hours ? $this->branch->opening_hours->format('Y-m-d H:i:s') : null,
                    'closing_hours' => $this->branch->closing_hours ? $this->branch->closing_hours->format('Y-m-d H:i:s') : null,
                    'map_coordinates' => $this->branch->map_coordinates ?? null,
                    'image' => $this->branch->image ? asset('storage/' . $this->branch->image) : null,

                ];
            }),'branch_manager' => $this->whenLoaded('branchManager', function () {
                if (!$this->branchManager) {
                    return null;
                }
                return [
                    'id' => $this->branchManager->id,
                    'name' => $this->branchManager->name,
                    'email' => $this->branchManager->email,
                    'phone' => $this->branchManager->phone,
                    'image' => $this->branchManager->image ? asset('storage/' . $this->branchManager->image) : null,
                ];
            }),

            'items' => ReturnOrderItemResource::collection($this->whenLoaded('items')),
            'timelines' => $this->whenLoaded('timelines', fn () => TimelineResource::collection($this->timelines)),
        ];
    }

    /**
     * Get cancellation details for rejected return orders
     * Returns cancellation object with reason, timestamp, and who cancelled it
     * Shows cancellation if there was a rejection (regardless of current status)
     */
    private function getCancellationDetails(): ?array
    {
        // Return cancellation if there was a rejection (regardless of current status)
        // Check if rejected_at or rejection_reason exists
        if (!$this->rejected_at && !$this->rejection_reason) {
            return null;
        }

        // Get cancellation reason (rejection_reason)
        $cancellationReason = $this->rejection_reason ?? null;

        // Get cancelled_at (rejected_at)
        $cancelledAt = $this->rejected_at?->format('Y-m-d\TH:i:s\Z') ?? null;

        // Get cancelled_by information
        $cancelledBy = $this->getCancelledByInfo();

        return [
            'cancellation_reason' => $cancellationReason,
            'cancelled_at' => $cancelledAt,
            'cancelled_by' => $cancelledBy,
        ];
    }

    /**
     * Get information about who cancelled/rejected the return
     */
    private function getCancelledByInfo(): ?array
    {
        if (!$this->responded_by) {
            return null;
        }

        // Check if responded_by is a supplier
        $supplierInfo = $this->getSupplierInfo();
        if ($supplierInfo) {
            return $supplierInfo;
        }

        // Check if responded_by is a branch manager/user
        $userInfo = $this->getUserInfo();
        if ($userInfo) {
            return $userInfo;
        }

        // Fallback: return minimal info with type 'user'
        return [
            'id' => $this->responded_by,
            'name' => null,
            'type' => 'user',
        ];
    }

    /**
     * Get supplier info if responded_by is a supplier
     */
    private function getSupplierInfo(): ?array
    {
        if (!$this->relationLoaded('supplier') || !$this->supplier) {
            return null;
        }

        // Check if responded_by matches supplier_id
        $isSupplier = $this->responded_by === $this->supplier->id ||
            ($this->supplier_id && $this->responded_by === $this->supplier_id);

        if ($isSupplier) {
            return [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name ?? null,
                'type' => 'supplier',
            ];
        }

        return null;
    }

    /**
     * Get user/branch manager info if responded_by is a user
     */
    private function getUserInfo(): ?array
    {
        if ($this->relationLoaded('respondedBy') && $this->respondedBy) {
            return [
                'id' => $this->respondedBy->id,
                'name' => $this->respondedBy->name ?? null,
                'type' => 'user',
            ];
        }

        return null;
    }
}
