<?php

namespace Modules\Aggregator\Transformers;


use Illuminate\Http\Resources\Json\JsonResource;

class AggregatorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'logo' => $this->logo_url,
            'description' => $this->description,
            'commission_rate' => (float) $this->commission_rate,
            'commission_percentage' => $this->commission_percentage,
            'status' => [
                'value' => $this->is_active,
                'label' => $this->status_label,
                'color' => $this->status_color,
            ],
            'integration_type' => $this->integration_type,
            'has_integration' => $this->hasIntegration(),
            'branches_count' => $this->getTotalBranches(),
            'enabled_branches_count' => $this->getEnabledBranches(),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}

