<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BrandOwner\Models\BrandOwnerSettingRetention;

class BrandOwnerSettingRetentionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var BrandOwnerSettingRetention $s */
        $s = $this->resource;

        return [
            'photo_retention_years' => (int) $s->photo_retention_years,
            'handover_reports_retention_years' => (int) $s->handover_reports_retention_years,
        ];
    }
}
