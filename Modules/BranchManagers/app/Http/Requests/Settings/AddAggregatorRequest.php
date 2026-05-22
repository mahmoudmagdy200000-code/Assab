<?php

namespace Modules\BranchManagers\Http\Requests\Settings;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Validates adding an aggregator to the authenticated manager's branch.
 */
class AddAggregatorRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof BranchManager;
    }

    public function rules(): array
    {
        return [
            'aggregator_id' => [
                'required',
                'string',
                Rule::exists('aggregators', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['aggregator_id' => 'aggregator'];
    }
}
