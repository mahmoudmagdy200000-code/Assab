<?php

namespace Modules\Aggregator\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aggregator list item (mobile picker on shift-end, dashboard lists).
 *
 * Every string field is emitted as a STRING, never null: aggregators are
 * seeded without a logo or description, and the mobile client casts those
 * fields to String — a null made the end-shift «add aggregators» sheet fail
 * with "type 'Null' is not a subtype of type 'String' in type cast".
 */
class AggregatorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'code' => (string) $this->code,
            'logo' => (string) ($this->logo_url ?? ''),
            'description' => (string) ($this->description ?? ''),
            'commission_rate' => (float) $this->commission_rate,
            'commission_percentage' => $this->commission_percentage,
            'status' => [
                'value' => $this->is_active,
                'label' => $this->status_label,
                'color' => $this->status_color,
            ],
            'integration_type' => (string) ($this->integration_type ?? ''),
            'has_integration' => $this->hasIntegration(),
            'branches_count' => $this->getTotalBranches(),
            'enabled_branches_count' => $this->getEnabledBranches(),
            'created_at' => (string) optional($this->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}
