<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'return_quantity' => $this->return_quantity,
            'unit' => $this->unit,
            'quality_reason' => $this->quality_reason,
            'return_amount' => $this->return_amount,
            'files' => $this->files,
            'notes' => $this->notes,
        ];
    }
}
