<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BrandOwner\Models\BrandOwnerSettingApproval;

class BrandOwnerSettingApprovalResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var BrandOwnerSettingApproval $s */
        $s = $this->resource;

        return [
            'response_time_hours' => (int) $s->response_time_hours,
            'initial_audit_minimum_assets' => (int) $s->initial_audit_minimum_assets,
            'initial_audit_excellent_ratio' => (int) $s->initial_audit_excellent_ratio,
            'personal_approval_for_all_new_branches' => (bool) $s->personal_approval_for_all_new_branches,
            'auto_approve_transfers_within' => (int) $s->auto_approve_transfers_within,
            'auto_approve_modifications_within' => (int) $s->auto_approve_modifications_within,
            'auto_approve_disposals_within' => (int) $s->auto_approve_disposals_within,
            'critical_assets_always_require_approval' => (bool) $s->critical_assets_always_require_approval,
        ];
    }
}
