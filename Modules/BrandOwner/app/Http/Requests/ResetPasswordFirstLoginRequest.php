<?php

namespace Modules\BrandOwner\Http\Requests;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordFirstLoginRequest extends FormRequest
{
    use NormalizesIdentifier;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Optional proofs for a client that does not attach the first-login
            // token as a Bearer header (see FirstLoginActivationResolver).
            'token' => 'sometimes|string',
            'identifier' => 'sometimes|string',
            'default_password' => 'sometimes|string',
            'password' => 'required|string|min:8|confirmed',
        ];
    }
}
