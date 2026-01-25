<?php

namespace Modules\Branch\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lat' => $this->lat ? (float) $this->lat : null,
            'lng' => $this->lng ? (float) $this->lng : null,
            'image' => $this->image ? asset('storage/' . $this->image) : null,
            'opening_hours' => $this->opening_hours ? $this->opening_hours->format('H:i:s') : null,
            'closing_hours' => $this->closing_hours ? $this->closing_hours->format('H:i:s') : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
