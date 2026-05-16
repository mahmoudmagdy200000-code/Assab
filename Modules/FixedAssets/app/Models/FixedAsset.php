<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\AssetStatus;

class FixedAsset extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_assets';

    protected $fillable = [
        'name',
        'code',
        'image',
        'branch_id',
        'zone_id',
        'asset_type_id',
        'assigned_to_type',
        'assigned_to_id',
        'status',
        'value',
        'acquired_at',
        'custody_started_at',
        'last_updated_at',
    ];

    protected $casts = [
        'status' => AssetStatus::class,
        'value' => 'decimal:2',
        'acquired_at' => 'datetime',
        'custody_started_at' => 'datetime',
        'last_updated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AssetZone::class, 'zone_id');
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'asset_type_id');
    }

    public function assignedTo(): MorphTo
    {
        return $this->morphTo('assigned_to');
    }

    public function modificationRequests(): HasMany
    {
        return $this->hasMany(ModificationRequest::class, 'asset_id');
    }

    public function transferDisposalItems(): HasMany
    {
        return $this->hasMany(TransferDisposalItem::class, 'asset_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function photoHistory(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->where('kind', 'asset_photo');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(Timeline::class, 'timelineable');
    }
}
