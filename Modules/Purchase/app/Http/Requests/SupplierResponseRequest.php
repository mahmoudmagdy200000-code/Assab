<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->input('action');

        $rules = [
            'action' => ['required', 'string', 'in:approve,reject'],
        ];

        if ($action === 'reject') {
            $rules['reason'] = ['required', 'string', 'max:1000'];
        }

        if ($action === 'approve') {
            $rules['response'] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Action is required.',
            'action.in' => 'Action must be either approve or reject.',
            'reason.required' => 'Rejection reason is required.',
        ];
    }
}
