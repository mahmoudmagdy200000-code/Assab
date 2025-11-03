<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptVarianceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'variance_type' => $this->variance_type,
            'variance_quantity' => $this->variance_quantity,
            'variance_amount' => $this->variance_amount,
            'action_taken' => $this->action_taken,
            'deduction_amount' => $this->deduction_amount,
            'deduction_reason' => $this->deduction_reason,
            'photo_evidence' => $this->photo_evidence,
            'notes' => $this->notes,
            'approved_by_supplier' => $this->approved_by_supplier,
            'supplier_response' => $this->supplier_response,
            'supplier_responded_at' => $this->supplier_responded_at?->format('Y-m-d H:i:s'),
        ];
    }
}
