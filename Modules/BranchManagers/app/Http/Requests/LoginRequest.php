<?php

namespace Modules\BranchManagers\Http\Requests;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Notification\Http\Concerns\DeviceTokenLoginRules;

class LoginRequest extends FormRequest
{
    use DeviceTokenLoginRules, NormalizesIdentifier;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'identifier' => 'required|string', // email or phone
            'password' => 'required|string',
            // Optional FCM device registration — see DeviceTokenLoginRules.
            ...self::deviceTokenLoginRules(),
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \App\Services\MobileAuthDiagnosticLogger::logAuthFailed('branch_manager', 'validation', $this);
        parent::failedValidation($validator);
    }
}
