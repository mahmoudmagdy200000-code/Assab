<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchasingOfficerOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quality_level' => ['required', 'string', 'in:economy,standard,premium'],
            'processing_time' => ['required', 'string', 'in:standard,urgent'],
            'preferred_delivery_date' => ['required', 'date', 'after:today'],
            'latest_delivery_date' => ['required', 'date', 'after_or_equal:preferred_delivery_date'],
            'special_instructions' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'uuid'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.item_logo' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
        ];
    }

    public function messages(): array
    {
        return [
            'quality_level.required' => 'Please select a quality level.',
            'processing_time.required' => 'Please select a processing time.',
            'preferred_delivery_date.required' => 'Preferred delivery date is required.',
            'preferred_delivery_date.after' => 'Preferred delivery date must be in the future.',
            'latest_delivery_date.required' => 'Latest delivery date is required.',
            'latest_delivery_date.after_or_equal' => 'Latest delivery date must be on or after the preferred date.',
            'items.required' => 'Please add at least one item to the order.',
        ];
    }
}

