<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class AsabRestaurant extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_restaurants';

    protected $fillable = ['brand_id', 'company_id', 'name', 'city', 'accountant_count', 'status'];

    protected $casts = ['accountant_count' => 'integer'];

    public function brand()
    {
        return $this->belongsTo(AsabBrand::class, 'brand_id');
    }
}
