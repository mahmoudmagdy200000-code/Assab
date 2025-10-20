<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->shift?->name ?? 'N/A',
            'start_time' => $this->shift?->start_time?->format('H:i') ?? null,
            'end_time' => $this->shift?->end_time?->format('H:i') ?? null,
            'branch_id' => $this->shift?->branch_id,
            'is_active' => $this->shift?->is_active ?? false,
        ];
    }
}
