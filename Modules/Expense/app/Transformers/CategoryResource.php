<?php

namespace Modules\Expense\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Category Resource
 */
class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type, // 'purchase' or 'expense'
            'parent' => $this->when($this->parent_id, [
                'id' => $this->parent?->id,
                'name' => $this->parent?->name,
            ]),
        ];
    }
}
