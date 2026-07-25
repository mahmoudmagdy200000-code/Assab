<?php

namespace Modules\BranchManagers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BranchManagers\Http\Requests\Concerns\NormalizesIdentifier;

class LoginRequest extends FormRequest
{
    use NormalizesIdentifier;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'identifier' => 'required|string', // email or phone
            'password' => 'required|string',
        ];
    }
}
