<?php

namespace Modules\Supplier\Http\Requests\Returns;

use Illuminate\Foundation\Http\FormRequest;

class ProcessReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_notes' => 'nullable|string|max:1000',
            'refund_amount' => 'nullable|numeric|min:0',
        ];
    }
}
