<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Support\PurchaseFileHelper;

class ReturnOrderResource extends JsonResource
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
            
            // Status
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            
            // Required action
            'required_action' => $this->required_action?->value,
            'required_action_label' => $this->required_action_label,
            
            // Financial
            'total_return_amount' => (float) $this->total_return_amount,
            'refund_amount' => $this->refund_amount ? (float) $this->refund_amount : null,
            'refund_method' => $this->refund_method,
            
            // Notes
            'additional_notes' => $this->additional_notes,
            
            // Response
            'response_notes' => $this->response_notes,
            'response_files' => $responseFilesList,
            'responded_at' => $this->responded_at?->format('Y-m-d H:i:s'),
            
            // Rejection
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            
            // Cancellation (for rejected returns)
            'cancellation' => $this->getCancellationDetails(),
            
            // Escalation
            'is_escalated' => $this->is_escalated,
            'escalation_reason' => $this->escalation_reason,
            'escalated_at' => $this->escalated_at?->format('Y-m-d H:i:s'),
            
            // Resolution
            'resolution_type' => $this->resolution_type,
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i:s'),
            
            // Timestamps
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            
            // Flags
            'is_draft' => $this->is_draft,
            'is_pending' => $this->is_pending,
            'is_completed' => $this->is_completed,
            
            // Relations
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
                    'image' => $this->branch->image ? asset('storage/' . $this->branch->image) : null,
                    'lat' => $this->branch->lat ? (float) $this->branch->lat : null,
                    'lng' => $this->branch->lng ? (float) $this->branch->lng : null,
                    'opening_hours' => $this->branch->opening_hours ? $this->branch->opening_hours->format('H:i:s') : null,
                    'closing_hours' => $this->branch->closing_hours ? $this->branch->closing_hours->format('H:i:s') : null,
                ];
            }),
            'supplier' => $this->whenLoaded('supplier', function () {
                if (!$this->supplier) {
                    return null;
                }
                return [
                    'id' => $this->supplier->id,
                    'name' => $this->supplier->name,
                    'image' => $this->supplier->image_url ?? null,
                    'status' => $this->supplier->status ?? 'offline',
                    'status_label' => $this->supplier->status_label ?? 'Offline',
                    'contact_methods' => $this->supplier->contact_methods ?? [],
                    'average_response_time_hours' => $this->supplier->average_response_time_hours ? (float) $this->supplier->average_response_time_hours : null,
                    'response_rate_percentage' => $this->supplier->response_rate_percentage ? (float) $this->supplier->response_rate_percentage : null,
                ];
            }),
            'items' => ReturnOrderItemResource::collection($this->whenLoaded('items')),
            'timelines' => TimelineResource::collection($this->whenLoaded('timelines')),
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

