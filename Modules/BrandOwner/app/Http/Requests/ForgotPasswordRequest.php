<?php

namespace Modules\BrandOwner\Http\Requests;

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
            'identifier' => 'required|string',
            'type' => 'required|in:email,phone',
        ];
    }
}
