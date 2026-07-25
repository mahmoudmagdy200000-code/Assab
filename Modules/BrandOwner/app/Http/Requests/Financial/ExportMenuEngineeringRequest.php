<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class ExportMenuEngineeringRequest extends BaseRequest
{
    use NormalizesFormatType;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Period inputs are optional: the service defaults to the current month
            // compared against the previous one (same contract as GET index).
            'year' => ['sometimes', 'nullable', 'integer'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'compared_year' => ['sometimes', 'nullable', 'integer'],
            'compared_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
