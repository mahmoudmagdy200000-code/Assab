<?php

namespace Modules\BranchManagers\Http\Requests;

use App\Http\Requests\Concerns\NormalizesIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
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
            'type' => 'required|in:email,phone',
        ];
    }
}
