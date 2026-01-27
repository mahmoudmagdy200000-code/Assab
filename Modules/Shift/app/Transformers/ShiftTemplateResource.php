<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isActive = (bool) ($this->is_active ?? true);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'branch_id' => $this->branch_id,
            'is_active' => $isActive,
            'disabled' => ! $isActive,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
