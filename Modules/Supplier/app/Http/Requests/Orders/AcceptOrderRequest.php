<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class AcceptOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_delivery_at' => 'nullable|date|after:now',
            'message' => 'nullable|string|max:1000',
        ];
    }
}

