<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierInfoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'supplier_name' => $this->name,
            'available_on_channel' => $this->contact_methods ?? [],
            'supplier_avg_response' => $this->average_response_time_hours !== null
                ? (float) $this->average_response_time_hours
                : null,
            'response_rate' => $this->response_rate_percentage !== null
                ? (float) $this->response_rate_percentage
                : null,
            'image' => $this->image_url,
            'status' => $this->status ?? 'offline',
        ];
    }
}
