<?php

namespace Modules\Cashier\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Same body as the other three mobile surfaces' first-login: the identifier the
 * branch manager registered (email or phone) and the password they issued.
 */
class FirstLoginRequest extends FormRequest
{
    use NormalizesIdentifier;

    public function authorize(): bool
    {
        // Public by definition — the caller holds no token yet. The handler
        // authenticates the credential itself.
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string', // email or phone
            'password' => 'required|string',
            'fcm_token' => 'sometimes|nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => 'Email or phone is required',
            'password.required' => 'Password is required',
        ];
    }
}
