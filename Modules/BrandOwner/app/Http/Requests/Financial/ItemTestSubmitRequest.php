<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class ItemTestSubmitRequest extends BaseRequest
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
            'branch_id' => ['required', 'string'],
            'item_name' => ['required', 'string'],
            'expected_selling_price' => ['required', 'numeric', 'min:0'],
            'production_cost' => ['required', 'numeric', 'min:0'],
            'expected_sales' => ['required', 'integer', 'min:0'],
            'expected_growth' => ['required', 'numeric'],
        ];
    }
}
