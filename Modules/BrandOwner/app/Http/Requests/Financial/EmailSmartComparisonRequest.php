<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class EmailSmartComparisonRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Period + branch inputs are optional: the service defaults to the
            // current month vs the previous one, on the DB default branch.
            'year' => ['sometimes', 'nullable', 'integer'],
            'month' => ['sometimes', 'nullable', 'integer'],
            'compared_year' => ['sometimes', 'nullable', 'integer'],
            'compared_month' => ['sometimes', 'nullable', 'integer'],
            'type' => ['required', 'in:month,branch'],
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'compared_branch_id' => ['sometimes', 'nullable', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
