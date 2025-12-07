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
            'search' => ['nullable', 'string', 'max:255'],
            'perspective' => ['nullable', 'string', 'in:submitted,received'],
            'type' => ['nullable', 'string', 'in:all,direct_supplier,via_purchasing_officer,internal_transfer,transfer_received,multiple_sources'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:closed,canceled,confirmed,partial_confirmation'],
            'date_range' => ['nullable', 'string', 'in:last_24h,last_7d,last_30d,custom'],
            'date_from' => ['nullable', 'date', 'required_if:date_range,custom'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'required_if:date_range,custom'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

