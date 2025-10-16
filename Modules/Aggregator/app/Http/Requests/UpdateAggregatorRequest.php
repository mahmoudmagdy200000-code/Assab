<?php

namespace Modules\Aggregator\Http\Requests;



use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAggregatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $aggregatorId = $this->route('aggregator')->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:255',
                Rule::unique('aggregators')->ignore($aggregatorId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('aggregators')->ignore($aggregatorId),
            ],
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
            'description' => 'nullable|string|max:1000',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|regex:/^(\+966|966|05)[0-9]{8}$/',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'integration_type' => 'sometimes|in:manual,api,webhook',
            'api_key' => 'nullable|string|max:500',
            'api_endpoint' => 'nullable|url|max:500',
            'webhook_url' => 'nullable|url|max:500',
        ];
    }
}

