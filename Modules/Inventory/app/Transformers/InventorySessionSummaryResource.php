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
                'performed_by' => $this->resource['session_summary']['performed_by'] ?? null,
                'completed_products' => $this->resource['session_summary']['completed_products'] ?? null,
                'completed_count' => $this->resource['session_summary']['completed_count'] ?? null,
                'total_count' => $this->resource['session_summary']['total_count'] ?? null,
                'status' => $this->resource['session_summary']['status'] ?? null,
                'status_label' => $this->resource['session_summary']['status_label'] ?? null,
            ],
            'all_inventoried_products' => $this->resource['all_inventoried_products'],
        ];
    }
}
