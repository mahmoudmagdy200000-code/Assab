<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class EmailMenuEngineeringRequest extends BaseRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer'],
            'compared_year' => ['required', 'integer'],
            'compared_month' => ['required', 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
