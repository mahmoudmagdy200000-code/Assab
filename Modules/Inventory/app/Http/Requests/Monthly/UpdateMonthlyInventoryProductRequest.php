<?php

namespace Modules\Inventory\Http\Requests\Monthly;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMonthlyInventoryProductRequest extends FormRequest
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
            'quantity_inventory' => ['required', 'numeric', 'min:0'],
            'count_method' => ['nullable', 'string', 'in:simple,slider'],
            'count_metadata' => ['nullable', 'array'],
            'count_metadata.full_containers' => ['nullable', 'integer', 'min:0'],
            'count_metadata.volume_per_container' => ['nullable', 'numeric', 'min:0'],
            'count_metadata.partial_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity_inventory.required' => 'The quantity is required.',
            'quantity_inventory.numeric' => 'The quantity must be a number.',
            'quantity_inventory.min' => 'The quantity must be at least 0.',
            'count_method.in' => 'The count method must be simple or slider.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }
}
