<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class ExportSalesChannelLevel2Request extends BaseRequest
{
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
            'branch_id' => ['required', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
