<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterPurchaseHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Search by item name
            'search' => ['nullable', 'string', 'max:255'],

            // Request Perspective: submitted (Close, Canceled) or received (Confirmed, Partial Confirmation)
            'perspective' => ['nullable', 'string', 'in:submitted,received'],

            // Type: All, Direct Supplier Order, Via Purchasing Officer, Internal Transfer
            'type' => ['nullable', 'string', 'in:all,direct_supplier,via_purchasing_officer,internal_transfer'],

            // Date filters: Last 24 hours, Last 7 Days, Last 30 Days, or Custom
            'date_range' => ['nullable', 'string', 'in:last_24h,last_7d,last_30d,custom'],
            'date_from' => ['nullable', 'date', 'required_if:date_range,custom'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'required_if:date_range,custom'],

            // Pagination
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
