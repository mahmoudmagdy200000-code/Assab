<?php

namespace Modules\Inventory\Http\Requests\Monthly;

use Illuminate\Foundation\Http\FormRequest;

class CreateMonthlyInventoryRequest extends FormRequest
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
            'inventory_date' => ['required', 'date'],
            'staff' => ['required', 'array', 'min:3'],
            'staff.*' => ['uuid', 'exists:cashiers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inventory_date.required' => 'The inventory date is required.',
            'inventory_date.date' => 'The inventory date must be a valid date.',
            'staff.required' => 'At least 3 staff members are required.',
            'staff.min' => 'At least 3 staff members are required.',
            'staff.*.exists' => 'One or more selected staff members are invalid.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }
}
