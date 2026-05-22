<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveComparisonRequest extends FormRequest
{
    /**
     * Zero-trust: only users bound to a branch may save a comparison.
     * Tenant isolation on read/delete is enforced via the forBranch scope.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->branch_id !== null;
    }

    /**
     * Get validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => 'Item ID is required to save a price comparison.',
            'item_id.uuid' => 'Item ID must be a valid UUID.',
            'quantity.numeric' => 'Quantity must be a number.',
            'quantity.min' => 'Quantity must be greater than 0.',
            'note.max' => 'Note may not be greater than 500 characters.',
        ];
    }

    /**
     * Accept item_id / quantity / note from either the JSON body or the query
     * string, so the save endpoint mirrors the compare-prices endpoint usage.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_id' => $this->input('item_id', $this->query('item_id')),
            'quantity' => $this->input('quantity', $this->query('quantity')),
            'note' => $this->input('note', $this->query('note')),
        ]);
    }
}
