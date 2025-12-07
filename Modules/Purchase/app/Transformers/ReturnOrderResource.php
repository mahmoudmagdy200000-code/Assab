<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ReturnOrderResource extends JsonResource
{
    public function toArray($request): array
    {
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
            'response_files' => $this->response_files,
            'responded_at' => $this->responded_at?->format('Y-m-d H:i:s'),
            
            // Rejection
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            
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
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn() => new PurchaseOrderResource($this->purchaseOrder)),
            'supplier' => $this->whenLoaded('supplier', fn() => new SupplierResource($this->supplier)),
            'items' => ReturnOrderItemResource::collection($this->whenLoaded('items')),
            'timelines' => TimelineResource::collection($this->whenLoaded('timelines')),
        ];
    }
}

