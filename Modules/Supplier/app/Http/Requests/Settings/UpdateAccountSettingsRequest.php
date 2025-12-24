<?php

namespace Modules\Supplier\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => 'sometimes|string|max:20|unique:suppliers,phone,' . auth()->id(),
            'email' => 'sometimes|email|max:255|unique:suppliers,email,' . auth()->id(),
        ];
    }
}

