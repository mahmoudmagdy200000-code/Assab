<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryComparisonResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = $this->resource;

        return [
            'period' => $data['period'] ?? [],
            'items' => $data['items'] ?? [],
        ];
    }
}
