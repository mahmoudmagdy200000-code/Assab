<?php

namespace Modules\Cashier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCashierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cashierId = $this->route('cashier')->id;

        return [
            'name' => 'sometimes|required|string|min:3|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('cashiers')->ignore($cashierId),
            ],
            'phone' => [
                'nullable',
                'string',
                'regex:/^(\+966|966|05)[0-9]{8}$/',
                Rule::unique('cashiers')->ignore($cashierId),
            ],
            'shift_ids' => 'sometimes|required|array|min:1',
            'shift_ids.*' => 'exists:shifts,id',
        ];
    }
}
