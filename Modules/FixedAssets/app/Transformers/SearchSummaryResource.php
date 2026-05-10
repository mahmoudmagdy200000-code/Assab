<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class SearchSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $row = (array) $this->resource;

        return [
            'total_items' => (int) ($row['total_items'] ?? 0),
            'total_items_matching_search' => (int) ($row['total_items_matching_search'] ?? 0),
            'total_items_matching_search_excellent_status' => (int) ($row['total_items_matching_search_excellent_status'] ?? 0),
            'total_items_matching_search_maintenance_status' => (int) ($row['total_items_matching_search_maintenance_status'] ?? 0),
            'total_items_matching_search_problem_status' => (int) ($row['total_items_matching_search_problem_status'] ?? 0),
        ];
    }
}
