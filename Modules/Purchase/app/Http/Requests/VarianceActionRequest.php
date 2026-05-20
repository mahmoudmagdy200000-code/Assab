<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VarianceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->input('action');

        $rules = [
            'action' => ['required', 'string', 'in:accept,compensatory_order,deduct_from_invoice'],
        ];

        if ($action === 'compensatory_order') {
            $rules = array_merge($rules, [
                'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
                'source' => ['nullable', 'string', 'max:100'],
                'deadline' => ['required', 'date', 'after:today'],
                'photos' => ['nullable', 'array'],
                'photos.*' => ['file', 'image', 'max:5120'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
        }

        if ($action === 'deduct_from_invoice') {
            $rules = array_merge($rules, [
                'amount' => ['required', 'numeric', 'min:0.01'],
                'reason' => ['required', 'string', 'in:short_quantity,damaged_quality'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Please select an action.',
            'deadline.required' => 'Delivery urgency deadline is required for compensatory orders.',
            'deadline.after' => 'Deadline must be in the future.',
            'amount.required' => 'Deduction amount is required.',
            'reason.required' => 'Reason for deduction is required.',
        ];
    }
}
