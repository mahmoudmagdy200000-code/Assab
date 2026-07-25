<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class EmailProfitAndLossRequest extends BaseRequest
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
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer'],
            // Optional: omitted / blank falls back to the DB default branch.
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
