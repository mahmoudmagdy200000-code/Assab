<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartReceivingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'items.*.quality' => ['required', 'string', 'in:excellent,normal,poor'],
            'items.*.temperature' => ['nullable', 'numeric'],
            'items.*.expiration_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Items array is required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required.',
            'items.*.item_id.required' => 'Item ID is required for each item.',
            'items.*.item_id.uuid' => 'Item ID must be a valid UUID.',
            'items.*.item_id.exists' => 'The selected item does not exist in the order.',
            'items.*.quantity_received.required' => 'Quantity received is required for each item.',
            'items.*.quantity_received.numeric' => 'Quantity received must be a number.',
            'items.*.quantity_received.min' => 'Quantity received must be at least 0.',
            'items.*.quality.required' => 'Quality is required for each item.',
            'items.*.quality.in' => 'Quality must be excellent, normal, or poor.',
            'items.*.temperature.numeric' => 'Temperature must be a number.',
            'items.*.expiration_date.date' => 'Expiration date must be a valid date.',
            'items.*.expiration_date.after_or_equal' => 'Expiration date must be today or later.',
            'items.*.photo.image' => 'Photo must be an image file.',
            'items.*.photo.mimes' => 'Photo must be a jpg, jpeg, or png file.',
            'items.*.photo.max' => 'Photo must not exceed 5MB.',
            'items.*.notes.max' => 'Notes must not exceed 1000 characters.',
        ];
    }
}
