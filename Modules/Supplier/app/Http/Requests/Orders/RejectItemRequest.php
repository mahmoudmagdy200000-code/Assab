<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class RejectItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
            // 'explanation' => 'required|string|max:1000',
        ];
    }
}

