<?php

namespace Modules\Supplier\Http\Requests\Auth;

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
            'identifier' => 'required|string', // email or phone
            'type' => 'required|in:email,phone',
        ];
    }
}
