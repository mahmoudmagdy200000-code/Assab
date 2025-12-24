<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class RequestModificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modification_type' => 'required|in:quantity,delivery_time,alternative_product',
            'modification_request' => 'required|string|max:2000',
            'suggested_changes' => 'nullable|array',
        ];
    }
}

