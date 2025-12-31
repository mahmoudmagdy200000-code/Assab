<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventorySessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'inventory_date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'end_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inventory_date.date' => 'The inventory date must be a valid date.',
            'start_time.date_format' => 'The start time must be in the format Y-m-d H:i:s.',
            'end_time.date_format' => 'The end time must be in the format Y-m-d H:i:s.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }
}

