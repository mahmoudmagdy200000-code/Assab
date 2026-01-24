<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ReturnOrderListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'return_number' => $this->return_number,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
