<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'uuid', 'exists:purchase_orders,id'],
            'required_action' => ['required', 'string', 'in:replacement,cash_refund,credit_future_order'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.return_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.quality_reason' => ['required', 'string', 'in:excellent,normal,poor'],
            'items.*.files' => ['nullable', 'array'],
            'items.*.files.*' => ['file', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_order_id.required' => 'Purchase order is required.',
            'required_action.required' => 'Please select a required action.',
            'items.required' => 'Please add at least one item to return.',
            'items.*.purchase_order_item_id.required' => 'Purchase order item ID is required.',
            'items.*.purchase_order_item_id.exists' => 'The selected purchase order item does not exist.',
            'items.*.return_quantity.required' => 'Return quantity is required.',
            'items.*.return_quantity.min' => 'Return quantity must be at least 0.001.',
            'items.*.quality_reason.required' => 'Quality reason is required.',
            'items.*.quality_reason.in' => 'Quality reason must be one of: excellent, normal, poor.',
            'items.*.files.*.max' => 'Each file must not exceed 5MB.',
        ];
    }
}
