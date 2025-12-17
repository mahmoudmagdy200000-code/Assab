<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Search by item name or order number
            'search' => ['nullable', 'string', 'max:255'],

            // Filter by order type
            'order_type' => ['nullable', 'string', 'in:direct_supplier,via_purchasing_officer,internal_transfer,multiple_sources'],

            // Filter by status
            'status' => ['nullable', 'string'],

            // Date filters
            'date_range' => ['nullable', 'string', 'in:last_24h,last_7d,last_30d,custom'],
            'date_from' => ['nullable', 'date', 'required_if:date_range,custom'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'required_if:date_range,custom'],

            // Pagination
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
