<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmInventorySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*.itemId' => ['required_with:items', 'string', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
