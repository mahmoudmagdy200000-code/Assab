<?php

namespace Modules\Cashier\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use NormalizesIdentifier;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string',
            'reset_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $password = $this->input('password');

            if (! preg_match('/[A-Z]/', $password)) {
                $validator->errors()->add('password', 'Password must contain at least one uppercase letter');
            }

            if (! preg_match('/[0-9]/', $password)) {
                $validator->errors()->add('password', 'Password must contain at least one number');
            }
        });
    }
}
