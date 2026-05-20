<?php

namespace Modules\Supplier\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:2048',
            'address' => 'sometimes|string|max:1000',
            'company_name' => 'sometimes|string|max:255',
        ];
    }
}
