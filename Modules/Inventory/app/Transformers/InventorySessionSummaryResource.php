<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class InventorySessionSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'session_summary' => [
                'inventory_date' => $this->resource['session_summary']['inventory_date'],
                'start_time' => $this->resource['session_summary']['start_time'],
                'end_time' => $this->resource['session_summary']['end_time'],
                'time_taken' => $this->resource['session_summary']['time_taken'],
            ],
            'all_inventoried_products' => $this->resource['all_inventoried_products'],
        ];
    }
}

