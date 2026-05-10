<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\DisposalMethod;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TransferDirection;
use Modules\FixedAssets\Enums\TransferDisposalKind;

class TransferDisposalRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_asset_transfer_disposal_requests';

    protected $fillable = [
        'kind',
        'branch_id',
        'requested_by_id',
        'recipient_branch_id',
        'auto_approve',
        'disposal_date',
        'disposal_time',
        'disposal_method',
        'direction',
        'status',
        'approved_at',
    ];

    protected $casts = [
        'kind' => TransferDisposalKind::class,
        'auto_approve' => 'boolean',
        'disposal_date' => 'date',
        'disposal_method' => DisposalMethod::class,
        'direction' => TransferDirection::class,
        'status' => RequestStatus::class,
        'approved_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function recipientBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'recipient_branch_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'requested_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferDisposalItem::class, 'request_id');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(Timeline::class, 'timelineable');
    }
}
