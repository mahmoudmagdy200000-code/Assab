<?php

namespace Modules\Supplier\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use NormalizesIdentifier;

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
        ];
    }
}
