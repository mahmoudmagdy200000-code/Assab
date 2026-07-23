<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class PriceSimulatorSubmitRequest extends BaseRequest
{
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
            'item_id' => ['required', 'string'],
            'change_price' => ['required', 'numeric', 'min:0'],
            'expected_growth_decline' => ['required', 'numeric'],
        ];
    }
}
