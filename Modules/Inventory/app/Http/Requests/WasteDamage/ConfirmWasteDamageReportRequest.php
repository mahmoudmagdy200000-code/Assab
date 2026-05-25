<?php

namespace Modules\Inventory\Http\Requests\WasteDamage;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmWasteDamageReportRequest extends FormRequest
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
