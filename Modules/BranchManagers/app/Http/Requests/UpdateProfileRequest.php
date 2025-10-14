<?php

namespace Modules\BranchManagers\Http\Requests;
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
        $managerId = auth()->id();

        return [
            'name' => 'sometimes|required|string|min:3|max:255',
            'phone' => [
                'nullable',
                'string',
                'regex:/^(\+966|966|05)[0-9]{8}$/',
                Rule::unique('branch_managers')->ignore($managerId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.min' => 'Name must be at least 3 characters',
            'phone.regex' => 'Please provide a valid Saudi phone number',
            'phone.unique' => 'This phone number is already registered',
        ];
    }
}
