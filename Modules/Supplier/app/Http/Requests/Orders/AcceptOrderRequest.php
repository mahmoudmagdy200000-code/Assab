<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class AcceptOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_delivery_at' => 'nullable|date|after:now',
            'ready_time' => ['nullable', 'string', 'in:non,30_min,1_hour,2_hours,3_hours_or_more'],
            'message' => 'nullable|string|max:1000',
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required_with:items', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
