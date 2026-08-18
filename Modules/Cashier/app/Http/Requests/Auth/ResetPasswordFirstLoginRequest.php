<?php

namespace Modules\Cashier\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «activate Account» for a cashier.
 *
 * Every proof field is `sometimes`, exactly like the other three surfaces: the
 * request may carry the first-login token (header or body) OR
 * `identifier` + `default_password`. Requiring the default password here — as
 * the old ActivationRequest did — is what shut out any build that holds the
 * token instead. Which proofs are acceptable is decided by
 * FirstLoginActivationResolver, not by validation.
 */
class ResetPasswordFirstLoginRequest extends FormRequest
{
    use NormalizesIdentifier;

    public function authorize(): bool
    {
        // Public: the handler demands a proof (token or the issued password)
        // before it will set anything.
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'sometimes|string',
            'identifier' => 'sometimes|string',
            'default_password' => 'sometimes|string',
            'password' => 'required|string|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 8 characters',
            'password.confirmed' => 'Password confirmation does not match',
        ];
    }

    public function withValidator($validator): void
    {
        // Kept from the previous ActivationRequest: the cashier screen has
        // always enforced an uppercase letter and a digit, and relaxing it here
        // would be a silent downgrade of an existing rule.
        $validator->after(function ($validator) {
            $password = (string) $this->input('password');

            if (! preg_match('/[A-Z]/', $password)) {
                $validator->errors()->add('password', 'Password must contain at least one uppercase letter');
            }

            if (! preg_match('/[0-9]/', $password)) {
                $validator->errors()->add('password', 'Password must contain at least one number');
            }
        });
    }
}
