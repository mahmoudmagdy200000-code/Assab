<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterPendingOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:direct_supplier,via_purchasing_officer,internal_transfer,transfer_received,multiple_sources'],
            'status' => ['nullable', 'string', 'in:draft,pending,pending_confirmation,pending_approval,partial_confirmation,confirmed,preparing,on_the_way,delayed'],
            'date_range' => ['nullable', 'string', 'in:last_24h,last_7d,last_30d,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

