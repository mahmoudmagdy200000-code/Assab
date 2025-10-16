<?php

namespace Modules\Cashier\Http\Requests;



use Illuminate\Foundation\Http\FormRequest;

class FilterCashierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|in:active,pending,deactivated',
            'search' => 'sometimes|string|min:2',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'per_page' => 'sometimes|integer|min:5|max:100',
            'sort_by' => 'sometimes|in:name,email,created_at,activated_at',
            'sort_order' => 'sometimes|in:asc,desc',

            ...$this->getPaginationRules(),
        ];
    }
}
