<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Http\Requests\Financial\Concerns\NormalizesFormatType;

class EmailOperationalProfitabilityRequest extends BaseRequest
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
            'email' => ['required', 'email'],
            'format_type' => ['sometimes', 'in:PDF,Excel'],
        ];
    }
}
