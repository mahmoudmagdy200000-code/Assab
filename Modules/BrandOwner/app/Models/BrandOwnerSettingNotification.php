<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOwnerSettingNotification extends Model
{
    use HasUuids;

    protected $table = 'brand_owner_setting_notifications';

    protected $fillable = [
        'brand_owner_id',
        'type',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function brandOwner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }
}
