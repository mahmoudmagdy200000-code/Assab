<?php

namespace Modules\Aggregator\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class AggregatorDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'logo' => $this->logo_url,
            'description' => $this->description,

            'contact' => [
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
            ],

            'commission' => [
                'rate' => (float) $this->commission_rate,
                'percentage' => $this->commission_percentage,
                'payment_terms' => $this->payment_terms,
            ],

            'status' => [
                'value' => $this->is_active,
                'label' => $this->status_label,
                'color' => $this->status_color,
            ],

            'integration' => [
                'type' => $this->integration_type,
                'has_integration' => $this->hasIntegration(),
                'api_endpoint' => $this->api_endpoint,
                'webhook_url' => $this->webhook_url,
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
                'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
