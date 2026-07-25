<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class ExportSalesChannelLevel2Request extends BaseRequest
{
    use NormalizesFormatType;

    /**
     * Role is enforced by the brand.owner route middleware (module-wide convention).
     */
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
            // Optional: omitted / blank falls back to the DB default branch.
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
