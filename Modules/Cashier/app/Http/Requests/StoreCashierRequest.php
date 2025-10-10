<?php

namespace Modules\Cashier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashierRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:cashiers,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:cashiers,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'image' => ['nullable', 'image', 'max:2048'], // Max 2MB
            'status' => ['nullable', 'in:active,inactive'],
            'branch_id' => ['required', 'exists:branches,id'],
            'created_by' => ['required', 'exists:branch_managers,id'],
            'shift_ids' => ['nullable', 'array'],
            'shift_ids.*' => ['exists:shifts,id'],


        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
