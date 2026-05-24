<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOwnerSettingApproval extends Model
{
    use HasUuids;

    protected $table = 'brand_owner_setting_approvals';

    protected $fillable = [
        'brand_owner_id',
        'response_time_hours',
        'initial_audit_minimum_assets',
        'initial_audit_excellent_ratio',
        'personal_approval_for_all_new_branches',
        'auto_approve_transfers_within',
        'auto_approve_modifications_within',
        'auto_approve_disposals_within',
        'critical_assets_always_require_approval',
    ];

    protected $casts = [
        'response_time_hours' => 'integer',
        'initial_audit_minimum_assets' => 'integer',
        'initial_audit_excellent_ratio' => 'integer',
        'personal_approval_for_all_new_branches' => 'boolean',
        'auto_approve_transfers_within' => 'integer',
        'auto_approve_modifications_within' => 'integer',
        'auto_approve_disposals_within' => 'integer',
        'critical_assets_always_require_approval' => 'boolean',
    ];

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }
}
