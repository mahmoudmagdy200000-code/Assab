<?php

namespace Modules\Supplier\Http\Requests\Returns;

use Illuminate\Foundation\Http\FormRequest;

class RejectReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|in:outside_return_policy,no_quality_issue,customer_damage,passed_return_window,other',
            'explanation' => 'required|string|max:1000',
        ];
    }
}

