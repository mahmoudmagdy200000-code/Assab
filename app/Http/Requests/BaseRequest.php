<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

abstract class BaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $this->formatValidationErrors($validator->errors());

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors,
                'meta' => [
                    'validation_failed' => true,
                    'error_count' => count($errors),
                ]
            ], 422)
        );
    }

    /**
     * Format validation errors for consistent response
     */
    protected function formatValidationErrors($errors): array
    {
        $formatted = [];

        foreach ($errors->toArray() as $field => $messages) {
            $formatted[$field] = [
                'messages' => $messages,
                'first_message' => $messages[0] ?? '',
                'count' => count($messages),
            ];
        }

        return $formatted;
    }

    /**
     * Get common validation rules
     */
    protected function getCommonRules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'sometimes|string|max:20',
            'status' => 'sometimes|string|in:active,inactive,pending,deactivated,suspended',
            'is_active' => 'sometimes|boolean',
            'description' => 'sometimes|string|max:1000',
            'notes' => 'sometimes|string|max:2000',
        ];
    }

    /**
     * Get pagination rules
     */
    protected function getPaginationRules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'sort_by' => 'sometimes|string|max:50',
            'sort_order' => 'sometimes|string|in:asc,desc',
        ];
    }

    /**
     * Get search rules
     */
    protected function getSearchRules(): array
    {
        return [
            'search' => 'sometimes|string|max:255',
            'search_fields' => 'sometimes|array',
            'search_fields.*' => 'string|max:50',
        ];
    }

    /**
     * Get date range rules
     */
    protected function getDateRangeRules(): array
    {
        return [
            'date_from' => 'sometimes|date|before_or_equal:date_to',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'date' => 'sometimes|date',
        ];
    }

    /**
     * Get file upload rules
     */
    protected function getFileUploadRules(): array
    {
        return [
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'file' => 'sometimes|file|mimes:pdf,doc,docx,xls,xlsx|max:5120',
            'logo' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:1024',
        ];
    }

    /**
     * Get ID validation rules
     */
    protected function getIdRules(): array
    {
        return [
            'id' => 'required|integer|exists:' . $this->getTableName() . ',id',
        ];
    }

    /**
     * Get table name for validation (to be overridden in child classes)
     */
    protected function getTableName(): string
    {
        return 'users';
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'email' => 'The :attribute must be a valid email address.',
            'unique' => 'The :attribute has already been taken.',
            'exists' => 'The selected :attribute is invalid.',
            'integer' => 'The :attribute must be an integer.',
            'string' => 'The :attribute must be a string.',
            'boolean' => 'The :attribute must be true or false.',
            'date' => 'The :attribute must be a valid date.',
            'image' => 'The :attribute must be an image.',
            'file' => 'The :attribute must be a file.',
            'mimes' => 'The :attribute must be a file of type: :values.',
            'max' => 'The :attribute may not be greater than :max characters.',
            'min' => 'The :attribute must be at least :min characters.',
            'in' => 'The selected :attribute is invalid.',
            'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
            'after_or_equal' => 'The :attribute must be a date after or equal to :date.',
        ];
    }

    /**
     * Get custom attribute names
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'password' => 'Password',
            'status' => 'Status',
            'is_active' => 'Active Status',
            'description' => 'Description',
            'notes' => 'Notes',
            'image' => 'Image',
            'file' => 'File',
            'logo' => 'Logo',
            'date_from' => 'Start Date',
            'date_to' => 'End Date',
            'date' => 'Date',
            'page' => 'Page',
            'per_page' => 'Per Page',
            'sort_by' => 'Sort By',
            'sort_order' => 'Sort Order',
            'search' => 'Search',
        ];
    }

    /**
     * Prepare the data for validation
     */
    protected function prepareForValidation(): void
    {
        // Convert empty strings to null for optional fields
        $data = $this->all();

        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }

        $this->merge($data);
    }

    /**
     * Get validated data with defaults
     */
    public function validatedWithDefaults(array $defaults = []): array
    {
        $validated = $this->validated();

        return array_merge($defaults, $validated);
    }

    /**
     * Check if request is for creating a new resource
     */
    public function isCreating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * Check if request is for updating an existing resource
     */
    public function isUpdating(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    /**
     * Get the route parameter value
     */
    public function getRouteParam(string $param, $default = null)
    {
        return $this->route($param, $default);
    }

    /**
     * Get pagination parameters
     */
    public function getPaginationParams(): array
    {
        return [
            'page' => $this->get('page', 1),
            'per_page' => $this->get('per_page', 15),
            'sort_by' => $this->get('sort_by', 'created_at'),
            'sort_order' => $this->get('sort_order', 'desc'),
        ];
    }

    /**
     * Get search parameters
     */
    public function getSearchParams(): array
    {
        return [
            'search' => $this->get('search'),
            'search_fields' => $this->get('search_fields', []),
        ];
    }

    /**
     * Get date range parameters
     */
    public function getDateRangeParams(): array
    {
        return [
            'date_from' => $this->get('date_from'),
            'date_to' => $this->get('date_to'),
            'date' => $this->get('date'),
        ];
    }
}
