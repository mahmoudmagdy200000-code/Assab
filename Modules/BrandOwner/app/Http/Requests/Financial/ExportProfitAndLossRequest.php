<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class ExportProfitAndLossRequest extends BaseRequest
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
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            // Optional: omitted / blank falls back to the DB default branch.
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
