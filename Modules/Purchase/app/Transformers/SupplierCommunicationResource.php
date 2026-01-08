<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierCommunicationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'supplier_name' => $this->resource['supplier_name'] ?? null,
            'supplier_image' => $this->resource['supplier_image'] ?? null,
            'supplier_status' => $this->resource['supplier_status'] ?? 'offline',
            'available_contact_methods' => [
                'whatsapp' => $this->resource['available_contact_methods']['whatsapp'] ?? false,
                'sms' => $this->resource['available_contact_methods']['sms'] ?? false,
                'email' => $this->resource['available_contact_methods']['email'] ?? false,
                'in_app_chat' => $this->resource['available_contact_methods']['in_app_chat'] ?? true,
            ],
            'response_statistics' => [
                'average_response_time_hours' => $this->resource['response_statistics']['average_response_time_hours'] ?? 0,
                'response_rate_percentage' => $this->resource['response_statistics']['response_rate_percentage'] ?? 0,
            ],
            'contact_history' => $this->resource['contact_history'] ?? [],
        ];
    }
}
