<?php

namespace Modules\Cashier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class CashierResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image_url,
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],
            'status' => [
                'value' => $this->status,

            ],
            'created_by' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ],
            'shifts_count' => $this->shifts_count ?? $this->getTotalShiftsCount(),
            'activated_at' => $this->activated_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        
        ];
    }
}
