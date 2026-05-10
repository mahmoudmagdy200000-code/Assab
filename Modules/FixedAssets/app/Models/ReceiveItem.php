<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiveItem extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_receive_items';

    protected $fillable = [
        'receive_session_id',
        'pending_receipt_id',
        'fixed_asset_id',
        'assigned_zone_id',
        'asset_type_id',
        'asset_count',
        'excellent_count',
        'need_attention_count',
        'problem_count',
        'image_path',
    ];

    protected $casts = [
        'asset_count' => 'integer',
        'excellent_count' => 'integer',
        'need_attention_count' => 'integer',
        'problem_count' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReceiveSession::class, 'receive_session_id');
    }

    public function pendingReceipt(): BelongsTo
    {
        return $this->belongsTo(PendingReceipt::class, 'pending_receipt_id');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AssetZone::class, 'assigned_zone_id');
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'asset_type_id');
    }
}
