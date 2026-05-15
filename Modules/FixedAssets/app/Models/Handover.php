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
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\HandoverStatus;

class Handover extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_asset_handovers';

    protected $fillable = [
        'session_code',
        'branch_id',
        'sender_id',
        'recipient_type',
        'recipient_id',
        'status',
        'note',
        'sent_invitations',
        'qr_code_image_path',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => HandoverStatus::class,
        'sent_invitations' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'sender_id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo('recipient');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HandoverItem::class, 'handover_id');
    }

    public function zoneApprovals(): HasMany
    {
        return $this->hasMany(HandoverZoneApproval::class, 'handover_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(HandoverSignature::class, 'handover_id');
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(Timeline::class, 'timelineable');
    }
}
