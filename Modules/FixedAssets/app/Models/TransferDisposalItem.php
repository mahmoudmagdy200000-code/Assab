<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class TransferDisposalItem extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_transfer_disposal_items';

    protected $fillable = [
        'request_id',
        'asset_id',
        'transfer_reason',
        'disposal_reason',
        'condition_description',
        'status',
        'dest_decided_by_id',
        'dest_decided_at',
        'bo_decided_by_id',
        'bo_decided_at',
        'rejection_reason',
        'cancellation',
    ];

    protected $casts = [
        'status' => \Modules\FixedAssets\Enums\RequestStatus::class,
        'dest_decided_at' => 'datetime',
        'bo_decided_at' => 'datetime',
        'cancellation' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TransferDisposalRequest::class, 'request_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function documentationPhoto(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('kind', 'documentation_photo');
    }

    public function visualEvidence(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('kind', 'visual_evidence');
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
