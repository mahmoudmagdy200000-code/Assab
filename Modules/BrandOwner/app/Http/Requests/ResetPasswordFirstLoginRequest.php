<?php

namespace Modules\BrandOwner\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordFirstLoginRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'password' => 'required|string|min:8|confirmed',
        ];
    }
}
