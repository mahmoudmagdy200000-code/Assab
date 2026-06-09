<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class AsabBrand extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_brands';

    protected $fillable = [
        'company_id', 'name', 'abbr', 'color', 'owner', 'owner_email',
        'plan', 'sub_status', 'expires', 'days_left', 'modules', 'status', 'auto_reminder_enabled',
    ];

    protected $casts = [
        'modules' => 'array',
        'expires' => 'datetime',
        'days_left' => 'integer',
        'auto_reminder_enabled' => 'boolean',
    ];

    public function restaurants()
    {
        return $this->hasMany(AsabRestaurant::class, 'brand_id');
    }

    public function company()
    {
        return $this->belongsTo(AsabCompany::class, 'company_id');
    }
}
