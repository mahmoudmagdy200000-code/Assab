<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class AsabSubscription extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_subscriptions';

    protected $fillable = [
        'company_id', 'brand_id', 'restaurant_id', 'plan', 'status',
        'expires_at', 'days_left', 'monthly_price', 'auto_renew', 'reminder_enabled',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'days_left' => 'integer',
        'monthly_price' => 'integer',
        'auto_renew' => 'boolean',
        'reminder_enabled' => 'boolean',
    ];

    public function restaurant()
    {
        return $this->belongsTo(AsabRestaurant::class, 'restaurant_id');
    }

    public function brand()
    {
        return $this->belongsTo(AsabBrand::class, 'brand_id');
    }
}
