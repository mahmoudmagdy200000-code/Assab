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
            'reason' => 'required|string|max:1000',
            'explanation' => 'required|string|max:1000',
        ];
    }
}
