<?php

namespace Modules\BrandOwner\Http\Requests;

use App\Http\Requests\BaseRequest;

/**
 * Body for POST /brand-owner/reports/custody/export.
 */
class ExportCustodyReportRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'year' => 'required|integer|min:2000|max:2100',
            'month_number' => 'required|integer|min:1|max:12',
            'format_type' => 'required|string|in:PDF,Excel',
        ];
    }
}
