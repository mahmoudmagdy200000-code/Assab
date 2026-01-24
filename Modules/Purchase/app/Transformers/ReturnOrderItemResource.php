<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Support\PurchaseFileHelper;

class ReturnOrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $files = $this->files ?? [];
        $fileList = array_map(fn ($f) => PurchaseFileHelper::toApiShape($f), $files);

        return [
            'id' => $this->id,
            'item_name' => $this->item_name,
            'item_logo' => $this->item_logo_url,
            'return_quantity' => (float) $this->return_quantity,
            'unit_of_measurement' => $this->unit_of_measurement,
            'quality_reason' => $this->quality_reason?->value,
            'quality_reason_label' => $this->quality_reason_label,
            'unit_price' => (float) $this->unit_price,
            'return_amount' => (float) $this->return_amount,
            'files' => $fileList,
            'notes' => $this->notes,
        ];
    }
}

