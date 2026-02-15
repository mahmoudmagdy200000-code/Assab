<?php

namespace Modules\Cashier\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string', // email or phone
            'default_password' => 'required|string', // provided by branch manager
            'password' => 'required|string|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters',
            'password.confirmed' => 'Password confirmation does not match',
            'default_password.required' => 'Default password provided by your branch manager is required',
            'identifier.required' => 'Email or phone is required',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if password meets complexity requirements
            $password = $this->input('password');

            if (!preg_match('/[A-Z]/', $password)) {
                $validator->errors()->add('password', 'Password must contain at least one uppercase letter');
            }

            if (!preg_match('/[0-9]/', $password)) {
                $validator->errors()->add('password', 'Password must contain at least one number');
            }
        });
    }
}
