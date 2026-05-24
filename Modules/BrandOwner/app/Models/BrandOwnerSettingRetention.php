<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOwnerSettingRetention extends Model
{
    use HasUuids;

    protected $table = 'brand_owner_setting_retentions';

    protected $fillable = [
        'brand_owner_id',
        'photo_retention_years',
        'handover_reports_retention_years',
    ];

    protected $casts = [
        'photo_retention_years' => 'integer',
        'handover_reports_retention_years' => 'integer',
    ];

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }
}
