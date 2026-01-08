<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderTrackingResource extends JsonResource
{
    public function toArray($request): array
    {
        $stages = $this->resource;

        return [
            'order_id' => $stages['order_id'] ?? null,
            'stages' => [
                'preparing' => $stages['preparing'] ?? null,
                'out_for_delivery' => $stages['out_for_delivery'] ?? null,
                'delivered' => $stages['delivered'] ?? null,
                'order_confirmation' => $stages['order_confirmation'] ?? null,
                'variance_logged' => $stages['variance_logged'] ?? null,
            ],
        ];
    }
}
