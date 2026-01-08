<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_contact' => ['nullable', 'string', 'max:50'],
            'driver_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'arrival_time' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'driver_image.image' => 'Driver image must be an image file.',
            'driver_image.max' => 'Driver image must not exceed 5MB.',
        ];
    }
}
