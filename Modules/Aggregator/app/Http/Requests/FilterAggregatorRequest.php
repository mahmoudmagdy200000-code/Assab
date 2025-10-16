<?php

namespace Modules\Aggregator\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;

class FilterAggregatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => 'sometimes|boolean',
            'integration_type' => 'sometimes|in:manual,api,webhook',
            'search' => 'sometimes|string|min:2',
            'has_integration' => 'sometimes|in:true,false',
            'per_page' => 'sometimes|integer|min:5|max:100',
            'sort_by' => 'sometimes|in:name,code,commission_rate,created_at',
            'sort_order' => 'sometimes|in:asc,desc',
        ];
    }
}

