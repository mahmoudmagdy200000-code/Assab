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
            'location' => $this->location,
            'image' => $this->image,
            'opening_hours' => $this->opening_hours,
            'map_coordinates' => $this->map_coordinates,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
