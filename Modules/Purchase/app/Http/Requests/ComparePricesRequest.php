<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComparePricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => 'Item ID is required for price comparison.',
            'quantity.required' => 'Quantity is required for price comparison.',
        ];
    }
}

