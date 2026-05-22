<?php

namespace Modules\BranchManagers\Http\Requests\Settings;

use App\Http\Requests\BaseRequest;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Shared request for the boolean `enabled` toggle used by both the
 * aggregator status endpoint and the notification toggle endpoints.
 */
class ToggleEnabledRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof BranchManager;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
