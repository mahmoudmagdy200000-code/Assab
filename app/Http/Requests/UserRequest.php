<?php

namespace App\Http\Requests;

use App\Http\Requests\BaseRequest;

class UserRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = array_merge(
            $this->getCommonRules(),
            $this->getPaginationRules(),
            $this->getSearchRules(),
            $this->getDateRangeRules(),
            $this->getFileUploadRules()
        );

        // Add specific rules based on HTTP method
        if ($this->isCreating()) {
            $rules = array_merge($rules, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users,email',
                'phone' => 'required|string|max:20|unique:users,phone',
                'password' => 'required|string|min:8|confirmed',
            ]);
        } elseif ($this->isUpdating()) {
            $userId = $this->getRouteParam('user');
            $rules = array_merge($rules, [
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|max:255|unique:users,email,' . $userId,
                'phone' => 'sometimes|required|string|max:20|unique:users,phone,' . $userId,
                'password' => 'sometimes|string|min:8|confirmed',
            ]);
        }

        return $rules;
    }

    /**
     * Get table name for validation
     */
    protected function getTableName(): string
    {
        return 'users';
    }

    /**
     * Get custom validation messages
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'The name field is required.',
            'email.required' => 'The email field is required.',
            'email.unique' => 'This email address is already registered.',
            'phone.required' => 'The phone field is required.',
            'phone.unique' => 'This phone number is already registered.',
            'password.required' => 'The password field is required.',
            'password.min' => 'The password must be at least 8 characters.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);
    }
}
