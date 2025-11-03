<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseTrackingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'driver_name' => $this->driver_name,
            'driver_phone' => $this->driver_phone,
            'driver_image' => $this->driver_image,
            'vehicle_number' => $this->vehicle_number,
            'estimated_arrival' => $this->estimated_arrival?->format('Y-m-d H:i:s'),
            'actual_arrival' => $this->actual_arrival?->format('Y-m-d H:i:s'),
            'delivery_address' => $this->delivery_address,
            'delivery_notes' => $this->delivery_notes,
            'quality_certificates' => $this->quality_certificates,
            'temperature' => $this->temperature,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
