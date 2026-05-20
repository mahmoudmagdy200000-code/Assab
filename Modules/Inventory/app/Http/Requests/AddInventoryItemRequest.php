<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddInventoryItemRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purchase_order_item_id' => ['required', 'uuid', 'exists:purchase_order_items,id'],
            'quantity_inventory' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purchase_order_item_id.required' => 'The purchase order item ID is required.',
            'purchase_order_item_id.exists' => 'The selected purchase order item does not exist.',
            'quantity_inventory.required' => 'The inventory quantity is required.',
            'quantity_inventory.numeric' => 'The inventory quantity must be a number.',
            'quantity_inventory.min' => 'The inventory quantity must be at least 0.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }
}
