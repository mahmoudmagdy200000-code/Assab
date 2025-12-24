<?php

namespace Modules\Supplier\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

class StartDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver_name' => 'required|string|max:255',
            'driver_contact' => 'required|string|max:20',
            'vehicle_number' => 'nullable|string|max:50',
            'transport_method' => 'nullable|string|max:100',
            'estimated_transport_hours' => 'nullable|integer|min:1',
            'expected_delivery_at' => 'nullable|date|after:now',
        ];
    }
}

