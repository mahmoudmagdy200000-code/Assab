<?php

namespace Modules\Supplier\Http\Requests\Returns;

use Illuminate\Foundation\Http\FormRequest;

class RejectReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('reason') && $this->filled('rejection_reason')) {
            $this->merge(['reason' => $this->input('rejection_reason')]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
            'explanation' => 'nullable|string|max:1000',
        ];
    }
}
