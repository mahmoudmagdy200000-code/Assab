<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class ExportSmartComparisonRequest extends BaseRequest
{
    use NormalizesFormatType;

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
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer'],
            'compared_year' => ['required', 'integer'],
            'compared_month' => ['required', 'integer'],
            'type' => ['required', 'in:month,branch'],
            'branch_id' => ['required', 'string'],
            'compared_branch_id' => ['nullable', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
