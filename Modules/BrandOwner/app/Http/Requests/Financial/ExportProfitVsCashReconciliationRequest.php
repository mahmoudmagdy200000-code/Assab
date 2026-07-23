<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class ExportProfitVsCashReconciliationRequest extends BaseRequest
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
            'format_type' => ['required', 'string', 'in:PDF,Excel'],
        ];
    }
}
