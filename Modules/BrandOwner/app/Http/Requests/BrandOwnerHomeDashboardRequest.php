<?php

namespace Modules\BrandOwner\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\BrandOwner\Models\BrandOwner;

/**
 * Optional query filters for GET /brand-owner/dashboard.
 *
 * All parameters are optional: the service falls back to the first branch
 * and the current month / year when they are not supplied.
 */
class BrandOwnerHomeDashboardRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // Routes are also guarded by the `brand.owner` middleware; this keeps
        // the request self-contained instead of blanket-returning true.
        return $this->user() instanceof BrandOwner;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|string|exists:branches,id',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'granularity' => 'nullable|string|in:daily,weekly,monthly',
        ];
    }

    /**
     * Normalised filters for the service.
     *
     * @return array{branch_id: ?string, month: ?int, year: ?int, granularity: ?string}
     */
    public function filters(): array
    {
        return [
            'branch_id' => $this->input('branch_id'),
            'month' => $this->filled('month') ? (int) $this->input('month') : null,
            'year' => $this->filled('year') ? (int) $this->input('year') : null,
            'granularity' => $this->input('granularity'),
        ];
    }
}
