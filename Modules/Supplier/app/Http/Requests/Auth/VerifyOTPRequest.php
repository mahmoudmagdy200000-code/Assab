<?php

namespace Modules\Supplier\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOTPRequest extends FormRequest
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
            'otp' => 'required|string|size:6',
        ];
    }
}
