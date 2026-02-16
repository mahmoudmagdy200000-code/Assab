<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveInventorySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales' => ['sometimes', 'array'],
            'sales.*' => ['numeric', 'min:0'],
            'recorded_waste' => ['sometimes', 'array'],
            'recorded_waste.*' => ['numeric', 'min:0'],
        ];
    }
}
