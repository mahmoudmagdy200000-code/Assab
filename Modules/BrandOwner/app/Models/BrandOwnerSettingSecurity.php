<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOwnerSettingSecurity extends Model
{
    use HasUuids;

    protected $table = 'brand_owner_setting_securities';

    protected $fillable = [
        'brand_owner_id',
        'data_encryption',
        'daily_backup_enabled',
        'daily_backup_interval_hours',
        'monthly_security_audit',
    ];

    protected $casts = [
        'data_encryption' => 'boolean',
        'daily_backup_enabled' => 'boolean',
        'daily_backup_interval_hours' => 'integer',
        'monthly_security_audit' => 'boolean',
    ];

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }
}
