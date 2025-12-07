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
            'items.*.purchase_order_item_id' => ['nullable', 'uuid'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.item_logo' => ['nullable', 'string'],
            'items.*.return_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.quality_reason' => ['required', 'string', 'in:excellent,normal,poor'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.files' => ['nullable', 'array'],
            'items.*.files.*' => ['file', 'max:5120'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_order_id.required' => 'Purchase order is required.',
            'required_action.required' => 'Please select a required action.',
            'items.required' => 'Please add at least one item to return.',
            'items.*.return_quantity.required' => 'Return quantity is required.',
            'items.*.quality_reason.required' => 'Quality reason is required.',
        ];
    }
}

