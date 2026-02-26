<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryReportResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = $this->resource;
        $summary = $data['summary'] ?? [];
        return [
            'inventory' => new MonthlyInventoryResource($data['inventory'] ?? null),
            'summary' => [
                'products_complete' => $summary['products_complete'] ?? 0,
                'products_total' => $summary['products_total'] ?? 0,
                'time_taken_seconds' => $summary['time_taken_seconds'] ?? null,
                'time_taken_formatted' => $summary['time_taken_formatted'] ?? null,
                'participants_count' => $summary['participants_count'] ?? 0,
                'total_value' => $summary['total_value'] ?? 0,
                'status' => $summary['status'] ?? null,
            ],
            'overall_assessment' => $data['overall_assessment'] ?? null,
            'recommendations' => $data['recommendations'] ?? [],
            'comparison_to_last_month' => $data['comparison_to_last_month'] ?? null,
            'team_contributions' => $data['team_contributions'] ?? [],
            'value_by_category' => $data['value_by_category'] ?? [],
            'products' => MonthlyInventoryProductResource::collection($data['products'] ?? []),
        ];
    }
}
