<?php

namespace Modules\Cashier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cashierId = auth()->id();

        return [
            'name' => 'sometimes|required|string|min:3|max:255',
            'phone' => [
                'nullable',
                'string',
                'regex:/^(\+966|966|05)[0-9]{8}$/',
                Rule::unique('cashiers')->ignore($cashierId),
            ],
            'current_password' => 'sometimes|required_with:new_password|string',
            'new_password' => 'sometimes|required|string|min:8|confirmed',
            'new_password_confirmation' => 'sometimes|required_with:new_password|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required_with' => 'Current password is required to change password',
            'new_password.confirmed' => 'New password confirmation does not match',
        ];
    }
}
