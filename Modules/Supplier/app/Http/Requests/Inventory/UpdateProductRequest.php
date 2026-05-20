<?php

namespace Modules\Supplier\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'unit_price' => 'sometimes|numeric|min:0',
            'economy_price' => 'nullable|numeric|min:0',
            'standard_price' => 'nullable|numeric|min:0',
            'premium_price' => 'nullable|numeric|min:0',
            'is_available' => 'nullable|boolean',
            'min_order_quantity' => 'nullable|numeric|min:0',
            'max_order_quantity' => 'nullable|numeric|min:0',
            'delivery_hours' => 'nullable|integer|min:1',
            'quality_level' => 'nullable|in:economy,standard,premium',
            'specifications' => 'nullable|array',
            'images' => 'nullable|array',
            'categories' => 'nullable|array',
        ];
    }
}
