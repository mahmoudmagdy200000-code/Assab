<?php

namespace Modules\Supplier\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'language' => 'sometimes|in:ar,en',
            'theme' => 'sometimes|in:light,dark',
        ];
    }
}

