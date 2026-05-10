<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class ReceiveSession extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_receive_sessions';

    protected $fillable = [
        'recipient_branch_id',
        'received_by_id',
        'type',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function recipientBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'recipient_branch_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'received_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiveItem::class, 'receive_session_id');
    }
}
