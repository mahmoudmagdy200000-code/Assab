<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryProofResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'recipient_name' => $this->recipient_name,
            'recipient_signature' => $this->recipient_signature ? asset('storage/' . $this->recipient_signature) : null,
            'delivery_photos' => $this->delivery_photos ? array_map(function ($photo) {
                return asset('storage/' . $photo);
            }, $this->delivery_photos) : [],
            'condition_confirmation' => $this->condition_confirmation,
            'acknowledgment_received_at' => $this->acknowledgment_received_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

