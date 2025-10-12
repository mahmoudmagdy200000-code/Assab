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
            'email' => 'required|email|exists:cashiers,email',
            'activation_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters',
            'password.confirmed' => 'Password confirmation does not match',
            'activation_token.required' => 'Activation token is required',
            'email.exists' => 'Email address not found',
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
