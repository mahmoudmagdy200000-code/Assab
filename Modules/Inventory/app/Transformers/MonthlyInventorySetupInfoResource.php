<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventorySetupInfoResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'date' => $this->resource['date'] ?? null,
            'number_of_products' => $this->resource['number_of_products'] ?? 0,
            'expected_time_minutes' => $this->resource['expected_time_minutes'] ?? null,
            'expected_time_label' => $this->resource['expected_time_label'] ?? null,
        ];
    }
}
