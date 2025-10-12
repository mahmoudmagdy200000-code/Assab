<?php

namespace Modules\Cashier\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;

class StoreCashierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:255',
            'email' => 'required|email|unique:cashiers,email',
            'phone' => 'nullable|string|unique:cashiers,phone',
            'shift_ids' => 'required|array|min:1',
            'shift_ids.*' => 'exists:shifts,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Cashier name is required',
            'name.min' => 'Cashier name must be at least 3 characters',
            'email.required' => 'Email address is required',
            'email.email' => 'Please provide a valid email address',
            'email.unique' => 'This email is already registered',
            'phone.regex' => 'Please provide a valid Saudi phone number',
            'phone.unique' => 'This phone number is already registered',
            'shift_ids.required' => 'At least one shift must be assigned',
            'shift_ids.*.exists' => 'Selected shift does not exist',
        ];
    }
}
