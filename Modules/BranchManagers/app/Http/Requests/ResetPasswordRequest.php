<?php

namespace Modules\BranchManagers\Http\Requests;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use NormalizesIdentifier;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'identifier' => 'required|string',
            'reset_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ];
    }
}
