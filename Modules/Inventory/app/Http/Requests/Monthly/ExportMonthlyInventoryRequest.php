<?php

namespace Modules\Inventory\Http\Requests\Monthly;

use Illuminate\Foundation\Http\FormRequest;

class ExportMonthlyInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', 'in:pdf,excel'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'format.required' => 'The export format is required.',
            'format.in' => 'The format must be pdf or excel.',
        ];
    }
}
