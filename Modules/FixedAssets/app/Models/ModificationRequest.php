<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\ModificationNextAction;
use Modules\FixedAssets\Enums\RequestStatus;

class ModificationRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_asset_modification_requests';

    protected $fillable = [
        'asset_id',
        'branch_id',
        'requested_by_id',
        'status',
        'new_status',
        'reason',
        'next_action',
        'approval_request_owner_note',
        'approved_at',
        'rejection_reason',
        'rejected_at',
        'bo_decided_by_id',
        'bo_decided_at',
        'cancellation',
    ];

    protected $casts = [
        'status' => RequestStatus::class,
        'new_status' => AssetStatus::class,
        'next_action' => ModificationNextAction::class,
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'bo_decided_at' => 'datetime',
        'cancellation' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'requested_by_id');
    }

    public function doneActions(): HasMany
    {
        return $this->hasMany(ModificationDoneAction::class, 'modification_request_id');
    }

    public function attachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('kind', 'modification_doc');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(Timeline::class, 'timelineable');
    }
}
