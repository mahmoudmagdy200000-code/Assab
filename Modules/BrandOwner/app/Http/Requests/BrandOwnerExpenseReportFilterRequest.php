<?php

namespace Modules\BrandOwner\Http\Requests;

use App\Http\Requests\BaseRequest;

/**
 * Optional query filters for GET /brand-owner/reports/expense/{reportId}.
 */
class BrandOwnerExpenseReportFilterRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'type' => 'nullable|string|in:quick_cash,invoice',
            'status' => 'nullable|string|in:draft,pending,approved,rejected',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'branch_id' => 'nullable|string|exists:branches,id',
        ];
    }

    public function filters(): array
    {
        return [
            'type' => $this->input('type'),
            'status' => $this->input('status'),
            'month' => $this->filled('month') ? (int) $this->input('month') : null,
            'year' => $this->filled('year') ? (int) $this->input('year') : null,
            'branch_id' => $this->input('branch_id'),
        ];
    }
}
