<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseModificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'modification_type' => $this->modification_type,
            'original_value' => $this->original_value,
            'new_value' => $this->new_value,
            'note' => $this->note,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'modified_by' => [
                'id' => $this->modifiedBy?->id,
                'name' => $this->modifiedBy?->name,
            ],
            'approved_by' => $this->when($this->approvedBy, [
                'id' => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
