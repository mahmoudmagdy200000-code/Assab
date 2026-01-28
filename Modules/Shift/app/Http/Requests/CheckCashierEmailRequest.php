<?php

namespace Modules\Shift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for checking if a cashier email is already registered.
 */
class CheckCashierEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user() instanceof \Modules\BranchManagers\Models\BranchManager;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Please provide a valid email address.',
        ];
    }
}
