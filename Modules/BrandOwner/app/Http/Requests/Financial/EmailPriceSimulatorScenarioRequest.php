<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class EmailPriceSimulatorScenarioRequest extends BaseRequest
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
            'scenario_id' => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
