<?php

namespace Modules\BranchManagers\Http\Requests;

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
            'language' => 'sometimes|in:en,ar',
            'theme' => 'sometimes|in:light,dark',
        ];
    }

    public function messages(): array
    {
        return [
            'language.in' => 'Language must be either English or Arabic',
            'theme.in' => 'Theme must be either light or dark',
        ];
    }
}
