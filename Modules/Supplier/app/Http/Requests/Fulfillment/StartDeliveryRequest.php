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
            'vehicle_number' => 'required|string|max:50',
            'expected_delivery_at' => 'required|string',
            'driver_photo' => 'nullable|file|image|mimes:jpeg,jpg,png|max:5120',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
