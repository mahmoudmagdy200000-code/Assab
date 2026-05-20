<?php

namespace Modules\Supplier\Http\Requests\Auth;

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
}
