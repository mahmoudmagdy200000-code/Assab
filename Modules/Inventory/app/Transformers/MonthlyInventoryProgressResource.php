<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryProgressResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = $this->resource;

        return [
            'elapsed_seconds' => $data['elapsed_seconds'] ?? 0,
            'elapsed_formatted' => $data['elapsed_formatted'] ?? '00:00:00',
            'completed' => $data['completed'] ?? 0,
            'total' => $data['total'] ?? 0,
            'completed_products' => MonthlyInventoryProductResource::collection($data['completed_products'] ?? []),
        ];
    }
}
