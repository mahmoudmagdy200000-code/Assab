<?php

namespace Modules\Cashier\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOTPRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string',
            'otp' => 'required|string|size:6',
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'OTP code is required',
            'otp.size' => 'OTP must be 6 digits',
        ];
    }
}
