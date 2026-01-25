<?php

namespace Modules\Inventory\Http\Requests\Monthly;

use Illuminate\Foundation\Http\FormRequest;

class ReturnToDraftRequest extends FormRequest
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
            'feedback' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'feedback.required' => 'Feedback is required when returning to draft.',
            'feedback.max' => 'The feedback may not be greater than 2000 characters.',
        ];
    }
}
