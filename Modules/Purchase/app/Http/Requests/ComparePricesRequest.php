<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComparePricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules that apply to the request.
     * Note: item_id and quantity are now query parameters, not body parameters.
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => 'Item ID is required for price comparison.',
            'item_id.uuid' => 'Item ID must be a valid UUID.',
            'quantity.numeric' => 'Quantity must be a number.',
            'quantity.min' => 'Quantity must be greater than 0.',
        ];
    }

    /**
     * Prepare the data for validation.
     * Since item_id and quantity are query parameters, we need to get them from the query string.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_id' => $this->query('item_id'),
            'quantity' => $this->query('quantity'),
        ]);
    }
}
