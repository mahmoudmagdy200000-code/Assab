<?php

namespace Modules\BranchManagers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Query filters for GET /branch-manager/price-comparisons.
 */
class ListPriceComparisonsRequest extends FormRequest
{
    /**
     * Zero-trust: only an active branch manager bound to a branch may list
     * their branch's saved comparisons.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof BranchManager && $user->branch_id !== null;
    }

    public function rules(): array
    {
        return [
            'timeKey' => ['nullable', 'string', 'in:last_24_hours,last_7_days,last_30_days'],
            'itemId' => ['nullable', 'string'],
            'supplierId' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'timeKey.in' => 'timeKey must be one of: last_24_hours, last_7_days, last_30_days.',
        ];
    }

    /**
     * Normalised filter set for the service layer.
     */
    public function filters(): array
    {
        return [
            'timeKey' => $this->input('timeKey'),
            'itemId' => $this->input('itemId'),
            'supplierId' => $this->input('supplierId'),
        ];
    }
}
