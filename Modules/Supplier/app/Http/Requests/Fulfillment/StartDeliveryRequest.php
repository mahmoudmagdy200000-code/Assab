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
            'driver_photo' => 'nullable|file|image|mimes:jpeg,jpg,png|max:5120',
            'vehicle_number' => 'nullable|string|max:50',
            'transport_method' => 'nullable|string|max:100',
            'estimated_transport_hours' => 'nullable|integer|min:1',
            'expected_delivery_at' => 'nullable|date|after:now',
            'gps_tracking_url' => 'nullable|string|url|max:500',
            'delivery_route' => 'nullable|array',
            'delivery_route.*.latitude' => 'required_with:delivery_route|numeric',
            'delivery_route.*.longitude' => 'required_with:delivery_route|numeric',
            'delivery_route.*.timestamp' => 'nullable|date',
        ];
    }
}

