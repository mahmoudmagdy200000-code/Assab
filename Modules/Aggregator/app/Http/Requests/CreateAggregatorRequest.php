<?php

namespace Modules\Aggregator\Http\Requests;



use Illuminate\Foundation\Http\FormRequest;

class CreateAggregatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:255|unique:aggregators,name',
            'code' => 'nullable|string|max:20|unique:aggregators,code',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
            'description' => 'nullable|string|max:1000',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|regex:/^(\+966|966|05)[0-9]{8}$/',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'integration_type' => 'required|in:manual,api,webhook',
            'api_key' => 'nullable|string|max:500',
            'api_endpoint' => 'nullable|url|max:500',
            'webhook_url' => 'nullable|url|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Aggregator name is required',
            'name.unique' => 'Aggregator name already exists',
            'code.unique' => 'Aggregator code already exists',
            'commission_rate.min' => 'Commission rate cannot be negative',
            'commission_rate.max' => 'Commission rate cannot exceed 100%',
            'integration_type.in' => 'Integration type must be manual, api, or webhook',
        ];
    }
}

