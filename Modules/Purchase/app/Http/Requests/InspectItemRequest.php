<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InspectItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity_received' => ['required', 'numeric', 'min:0'],
            'quality' => ['required', 'string', 'in:excellent,normal,poor'],
            'temperature' => ['nullable', 'numeric'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity_received.required' => 'Quantity received is required.',
            'quantity_received.numeric' => 'Quantity received must be a number.',
            'quantity_received.min' => 'Quantity received must be at least 0.',
            'quality.required' => 'Quality is required.',
            'quality.in' => 'Quality must be excellent, normal, or poor.',
            'expiry_date.after_or_equal' => 'Expiry date must be today or later.',
            'photo.image' => 'Photo must be an image file.',
            'photo.max' => 'Photo must not exceed 5MB.',
        ];
    }
}
