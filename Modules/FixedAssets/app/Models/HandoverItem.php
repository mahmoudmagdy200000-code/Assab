<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FixedAssets\Enums\RecipientInspectionResult;

class HandoverItem extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_handover_items';

    protected $fillable = [
        'handover_id',
        'asset_id',
        'zone_id',
        'zone_name_snapshot',
        'asset_name_snapshot',
        'asset_code_snapshot',
        'asset_image_snapshot',
        'asset_type_name_snapshot',
        'value_snapshot',
        'acquired_at_snapshot',
        'current_qty',
        'new_qty',
        'recipient_inspection',
        'recipient_note',
        'recipient_photo_path',
        'inspected_at',
        'is_deducted',
        'deduction',
    ];

    protected $casts = [
        'recipient_inspection' => RecipientInspectionResult::class,
        'value_snapshot' => 'decimal:2',
        'current_qty' => 'integer',
        'new_qty' => 'integer',
        'acquired_at_snapshot' => 'datetime',
        'inspected_at' => 'datetime',
        'is_deducted' => 'boolean',
        'deduction' => 'array',
    ];

    public function handover(): BelongsTo
    {
        return $this->belongsTo(Handover::class, 'handover_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AssetZone::class, 'zone_id');
    }
}
