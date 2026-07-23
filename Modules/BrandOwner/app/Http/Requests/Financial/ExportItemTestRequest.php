<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class ExportItemTestRequest extends BaseRequest
{
    use NormalizesFormatType;

    /**
     * Route middleware (brand.owner) enforces the role — module-wide convention.
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
            'test_id' => ['required', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
