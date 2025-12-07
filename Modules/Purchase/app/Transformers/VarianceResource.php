<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class VarianceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_name' => $this->item_name,
            'item_logo' => $this->item_logo_url,
            
            // Variance details
            'variance_type' => $this->variance_type?->value,
            'variance_type_label' => $this->variance_type?->label(),
            
            // Quantities
            'quantity_ordered' => (float) $this->quantity_ordered,
            'quantity_received' => (float) $this->quantity_received,
            'quantity_variance' => (float) $this->quantity_variance,
            
            // Quality
            'quality_ordered' => $this->quality_ordered?->value,
            'quality_received' => $this->quality_received?->value,
            
            // Financial
            'unit_price' => (float) $this->unit_price,
            'variance_amount' => (float) $this->variance_amount,
            
            // Action
            'action' => $this->action?->value,
            'action_label' => $this->action_label,
            'status' => $this->status,
            
            // Deduction
            'amount_to_deduct' => $this->amount_to_deduct ? (float) $this->amount_to_deduct : null,
            'deduction_reason' => $this->deduction_reason,
            
            // Response
            'supplier_response' => $this->supplier_response,
            'responded_at' => $this->responded_at?->format('Y-m-d H:i:s'),
            
            // Escalation
            'is_escalated' => $this->is_escalated,
            'escalation_reason' => $this->escalation_reason,
            'escalated_at' => $this->escalated_at?->format('Y-m-d H:i:s'),
            
            // Resolution
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i:s'),
            
            // Evidence
            'photo_evidence' => $this->photo_evidence,
            'additional_notes' => $this->additional_notes,
            
            // Flags
            'is_pending' => $this->is_pending,
            'is_resolved' => $this->is_resolved,
            
            // Compensatory order
            'compensatory_order' => $this->whenLoaded('compensatoryOrder', fn() => new CompensatoryOrderResource($this->compensatoryOrder)),
            
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}

