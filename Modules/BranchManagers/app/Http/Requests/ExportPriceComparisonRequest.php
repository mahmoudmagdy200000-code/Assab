<?php

namespace Modules\BranchManagers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Query parameters for GET /branch-manager/price-comparisons/{id}/export.
 */
class ExportPriceComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof BranchManager && $user->branch_id !== null;
    }

    public function rules(): array
    {
        return [
            'formatType' => ['required', 'string', 'in:PDF,Excel'],
        ];
    }

    public function messages(): array
    {
        return [
            'formatType.required' => 'formatType is required (PDF or Excel).',
            'formatType.in' => 'formatType must be either PDF or Excel.',
        ];
    }

    /**
     * formatType arrives as a query parameter; normalise common casings so
     * "pdf", "excel", "xlsx" all resolve to the canonical enum value.
     */
    protected function prepareForValidation(): void
    {
        $format = $this->input('formatType');

        if (is_string($format)) {
            $format = match (strtolower(trim($format))) {
                'pdf' => 'PDF',
                'excel', 'xlsx', 'xls' => 'Excel',
                default => $format,
            };
        }

        $this->merge(['formatType' => $format]);
    }
}
