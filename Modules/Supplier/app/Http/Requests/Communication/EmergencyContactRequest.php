<?php

namespace Modules\Supplier\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

class EmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|uuid|exists:branches,id',
            'contact_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_after_hours' => 'nullable|boolean',
            'escalation_level' => 'nullable|string|in:low,medium,high,critical',
        ];
    }
}

