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
            // Optional: a blank / omitted format falls back to PDF in the controller.
            'format_type' => ['sometimes', 'nullable', 'in:PDF,Excel'],
        ];
    }
}
