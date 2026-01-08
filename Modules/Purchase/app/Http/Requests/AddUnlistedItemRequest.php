<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddUnlistedItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['nullable', 'uuid', 'exists:items,id'],
            'item_name' => ['required', 'string', 'max:255'],
            'item_logo' => ['nullable', 'string', 'max:500'],
            'unit' => ['required', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'quality' => ['required', 'string', 'in:excellent,normal,poor'],
            'price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'temperature' => ['nullable', 'numeric'],
            'expiry_date' => ['nullable', 'date'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_name.required' => 'Item name is required.',
            'unit.required' => 'Unit of measurement is required.',
            'unit.in' => 'Unit must be kg, pk, unit, box, liter, or piece.',
            'quantity.required' => 'Quantity received is required.',
            'quantity.min' => 'Quantity must be greater than 0.',
            'quality.required' => 'Quality is required.',
            'quality.in' => 'Quality must be excellent, normal, or poor.',
            'photo.image' => 'Photo must be an image file.',
            'photo.max' => 'Photo must not exceed 5MB.',
        ];
    }
}
