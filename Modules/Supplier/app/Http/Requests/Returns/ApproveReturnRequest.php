<?php

namespace Modules\Supplier\Http\Requests\Returns;

use Illuminate\Foundation\Http\FormRequest;

class ApproveReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => 'nullable|string|max:1000',
            'refund_method' => 'nullable|string|max:100',
            'resolution_type' => 'nullable|string|in:refund,replacement,credit',
        ];
    }
}
