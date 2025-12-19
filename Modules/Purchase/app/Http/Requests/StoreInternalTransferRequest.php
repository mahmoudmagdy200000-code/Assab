<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInternalTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'priority' => ['nullable', 'string', 'in:high,normal'],
            'message' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'in:kg,pk,unit,box,liter,piece'],
            'items.*.quality' => ['nullable', 'string', 'in:economy,standard,premium'],
            'items.*.available_in_source' => ['nullable', 'numeric', 'min:0'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.cooling_status' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_branch_id.required' => 'Please select a source branch.',
            'from_branch_id.exists' => 'The selected branch does not exist.',
            'priority.required' => 'Please select a priority level.',
            'items.required' => 'Please add at least one item to transfer.',
            'items.*.item_id.required' => 'Item ID is required for each item.',
            'items.*.item_id.exists' => 'One or more selected items do not exist.',
            'items.*.quantity.required' => 'Quantity is required for each item.',
            'items.*.quantity.min' => 'Quantity must be greater than 0.',
        ];
    }
}
