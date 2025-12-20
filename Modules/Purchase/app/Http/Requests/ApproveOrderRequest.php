<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ready_time' => ['nullable', 'string', 'in:non,30_min,1_hour,2_hours,3_hours_or_more'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required_with:items', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

