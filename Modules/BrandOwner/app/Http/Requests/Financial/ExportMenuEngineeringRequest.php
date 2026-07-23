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
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer'],
            'compared_year' => ['required', 'integer'],
            'compared_month' => ['required', 'integer'],
            'branch_id' => ['required', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
