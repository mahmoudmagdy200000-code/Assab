<?php

namespace Modules\Supplier\Http\Requests\EmergencyOrders;

use Illuminate\Foundation\Http\FormRequest;

class RespondToEmergencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'can_fulfill' => 'required|boolean',
            'response_message' => 'nullable|string|max:1000',
        ];
    }
}
