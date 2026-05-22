<?php

namespace Modules\BranchManagers\Http\Requests\Settings;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates a self-service password reset for any authenticated user.
 */
class ResetMyPasswordRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'old_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'different:old_password',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'old_password' => 'current password',
            'password' => 'new password',
        ];
    }
}
