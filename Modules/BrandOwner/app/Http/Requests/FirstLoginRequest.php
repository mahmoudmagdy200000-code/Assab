<?php

namespace Modules\BrandOwner\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FirstLoginRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'identifier' => 'required|string',
            'password' => 'required|string',
        ];
    }
}
