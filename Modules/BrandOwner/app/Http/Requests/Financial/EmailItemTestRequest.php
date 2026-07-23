<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class EmailItemTestRequest extends BaseRequest
{
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
            'email' => ['required', 'email'],
        ];
    }
}
