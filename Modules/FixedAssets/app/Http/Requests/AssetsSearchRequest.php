<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\FixedAssets\Enums\QuickSearchKey;
use Modules\FixedAssets\Enums\SearchType;

class AssetsSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search_type' => ['required', 'string', 'in:'.implode(',', SearchType::values())],
            'zone_id' => ['required_if:search_type,general', 'nullable', 'uuid', 'exists:asset_zones,id'],
            'type_id' => ['required_if:search_type,general', 'nullable', 'uuid', 'exists:asset_types,id'],
            'employee_id' => ['required_if:search_type,general', 'nullable', 'uuid'],
            'date' => ['required_if:search_type,general', 'nullable', 'date'],
            'quick_search_key' => [
                'required_if:search_type,quick',
                'nullable',
                'string',
                'in:'.implode(',', QuickSearchKey::values()),
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
