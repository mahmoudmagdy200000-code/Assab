<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\RequestStatus;

class ReviewAuditRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_asset_review_audit_requests';

    protected $fillable = [
        'branch_id',
        'asset_id',
        'zone_id',
        'initiated_by_id',
        'status',
        'manager_name_snapshot',
        'zone_name_snapshot',
        'asset_name_snapshot',
        'asset_code_snapshot',
        'asset_type_snapshot',
        'image_url',
        'value',
        'total_assets',
        'audit_date',
        'conditions',
        'additional_notes',
        'rejection_reason',
        'cancellation',
        'dest_decided_by_id',
        'dest_decided_at',
        'bo_decided_by_id',
        'bo_decided_at',
    ];

    protected $casts = [
        'status' => RequestStatus::class,
        'value' => 'decimal:2',
        'audit_date' => 'date',
        'conditions' => 'array',
        'cancellation' => 'array',
        'dest_decided_at' => 'datetime',
        'bo_decided_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AssetZone::class, 'zone_id');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(Timeline::class, 'timelineable');
    }
}
