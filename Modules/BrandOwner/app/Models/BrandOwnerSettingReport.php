<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOwnerSettingReport extends Model
{
    use HasUuids;

    protected $table = 'brand_owner_setting_reports';

    protected $fillable = [
        'brand_owner_id',
        'monthly_reports',
        'quarterly_reports',
        'annual_reports',
    ];

    protected $casts = [
        'monthly_reports' => 'array',
        'quarterly_reports' => 'array',
        'annual_reports' => 'array',
    ];

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }
}
