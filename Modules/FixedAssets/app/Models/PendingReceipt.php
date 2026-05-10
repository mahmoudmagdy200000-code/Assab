<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;

class PendingReceipt extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fixed_asset_pending_receipts';

    protected $fillable = [
        'asset_name',
        'asset_code',
        'asset_image',
        'recipient_branch_id',
        'source',
        'status',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function recipientBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'recipient_branch_id');
    }
}
