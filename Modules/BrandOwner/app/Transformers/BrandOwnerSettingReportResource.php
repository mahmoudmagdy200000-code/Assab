<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BrandOwner\Models\BrandOwnerSettingReport;

class BrandOwnerSettingReportResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var BrandOwnerSettingReport $s */
        $s = $this->resource;

        return [
            'monthly_reports' => array_values((array) ($s->monthly_reports ?? [])),
            'quarterly_reports' => array_values((array) ($s->quarterly_reports ?? [])),
            'annual_reports' => array_values((array) ($s->annual_reports ?? [])),
        ];
    }
}
