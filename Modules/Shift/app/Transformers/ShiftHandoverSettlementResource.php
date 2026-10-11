<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Shift\Models\ShiftHandoverSettlement
 */
class ShiftHandoverSettlementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_receipt_id' => $this->original_receipt_id,
            'original_attempt_id' => $this->original_attempt_id,
            'original_request_type' => $this->original_request_type,
            'original_request_id' => $this->original_request_id,
            'settlement_type' => $this->settlement_type,
            'amount_halalas' => abs((int) $this->signed_delta_halalas),
            'signed_delta_halalas' => (int) $this->signed_delta_halalas,
            'amount_sar' => number_format(abs((int) $this->signed_delta_halalas) / 100, 2, '.', ''),
            'reason' => $this->reason,
            'operation_id' => $this->operation_id,
            'sender_type' => $this->sender_type,
            'sender_id' => $this->sender_id,
            'recipient_type' => $this->recipient_type,
            'recipient_id' => $this->recipient_id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
