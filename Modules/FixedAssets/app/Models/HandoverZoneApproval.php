<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoverZoneApproval extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_handover_zone_approvals';

    protected $fillable = [
        'handover_id',
        'zone_id',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function handover(): BelongsTo
    {
        return $this->belongsTo(Handover::class, 'handover_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AssetZone::class, 'zone_id');
    }
}
