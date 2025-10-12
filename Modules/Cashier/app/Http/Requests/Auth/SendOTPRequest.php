<?php

namespace Modules\Cashier\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SendOTPRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string',
            'type' => 'required|in:email,phone',
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => 'Email or phone number is required',
            'type.required' => 'OTP type is required',
            'type.in' => 'OTP type must be either email or phone',
        ];
    }
}
