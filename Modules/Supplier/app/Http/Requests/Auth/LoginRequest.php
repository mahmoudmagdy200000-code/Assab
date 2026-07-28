<?php

namespace Modules\Supplier\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Notification\Http\Concerns\DeviceTokenLoginRules;

class LoginRequest extends FormRequest
{
    use DeviceTokenLoginRules, NormalizesIdentifier;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => 'required|string', // email or phone
            'password' => 'required|string',
            'remember_me' => 'sometimes|boolean',
            // Optional FCM device registration — see DeviceTokenLoginRules.
            ...self::deviceTokenLoginRules(),
        ];
    }
}
