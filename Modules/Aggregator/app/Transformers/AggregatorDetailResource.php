<?php

namespace Modules\Aggregator\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aggregator detail. Same null-free string contract as AggregatorResource —
 * the mobile client type-casts these fields to String.
 */
class AggregatorDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'code' => (string) $this->code,
            'logo' => (string) ($this->logo_url ?? ''),
            'description' => (string) ($this->description ?? ''),

            'contact' => [
                'email' => (string) ($this->contact_email ?? ''),
                'phone' => (string) ($this->contact_phone ?? ''),
            ],

            'commission' => [
                'rate' => (float) $this->commission_rate,
                'percentage' => $this->commission_percentage,
                'payment_terms' => (string) ($this->payment_terms ?? ''),
            ],

            'status' => [
                'value' => $this->is_active,
                'label' => $this->status_label,
                'color' => $this->status_color,
            ],

            'integration' => [
                'type' => (string) ($this->integration_type ?? ''),
                'has_integration' => $this->hasIntegration(),
                'api_endpoint' => (string) ($this->api_endpoint ?? ''),
                'webhook_url' => (string) ($this->webhook_url ?? ''),
            ],

            'statistics' => $this->getAggregatorStatistics(),

            'branches' => $this->when($this->relationLoaded('branches'), function () {
                return $this->branches->map(function ($branch) {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'is_enabled' => $branch->pivot->is_enabled,
                    ];
                });
            }),

            'timestamps' => [
                'created_at' => (string) optional($this->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => (string) optional($this->updated_at)->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
