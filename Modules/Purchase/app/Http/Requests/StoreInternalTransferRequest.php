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
            'priority' => ['required', 'string', 'in:high,normal'],
            'message' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'uuid'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.item_logo' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'in:kg,pk,unit,box,liter,piece'],
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
        ];
    }
}

