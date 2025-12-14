<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetDirectSupplierItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:branch_item,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'min_availability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_delivery_hours' => ['nullable', 'integer', 'min:1'],
            'max_distance_km' => ['nullable', 'numeric', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', 'in:price,delivery_time,rating'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
