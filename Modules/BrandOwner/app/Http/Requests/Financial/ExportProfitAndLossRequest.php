<?php

namespace Modules\BrandOwner\Http\Requests\Financial;

use App\Http\Requests\BaseRequest;

class ExportProfitAndLossRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept format_type in any case (pdf, PDF, excel, Excel) — the exporter
     * itself is case-insensitive, so normalize to the canonical form here rather
     * than reject a valid intent on case alone.
     */
    protected function prepareForValidation(): void
    {
        $format = $this->input('format_type');

        if (is_string($format)) {
            $canonical = ['pdf' => 'PDF', 'excel' => 'Excel'];
            $this->merge([
                'format_type' => $canonical[strtolower(trim($format))] ?? $format,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['required', 'string'],
            'format_type' => ['required', 'in:PDF,Excel'],
        ];
    }
}
