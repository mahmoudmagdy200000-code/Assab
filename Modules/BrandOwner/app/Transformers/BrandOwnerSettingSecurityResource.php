<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BrandOwner\Models\BrandOwnerSettingSecurity;

class BrandOwnerSettingSecurityResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var BrandOwnerSettingSecurity $s */
        $s = $this->resource;

        return [
            'data_encryption' => (bool) $s->data_encryption,
            'daily_backup_enabled' => (bool) $s->daily_backup_enabled,
            'daily_backup_interval_hours' => (int) $s->daily_backup_interval_hours,
            'monthly_security_audit' => (bool) $s->monthly_security_audit,
        ];
    }
}
