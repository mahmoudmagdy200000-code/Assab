<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One cell of the accountant module grid: the modules an accountant handles for
 * ONE restaurant (ADM-3.3). The union of an accountant's cells is mirrored onto
 * `asab_user_roles.module_keys`, which is what auth/tenant resolution reads.
 *
 * No tenant scope: the grid is an admin-surface (أمين النظام) object keyed by
 * accountant + restaurant, and both sides are already tenant-scoped models.
 */
class AsabAccountantRestaurantModule extends Model
{
    use HasUuids;

    protected $table = 'asab_accountant_restaurant_modules';

    protected $fillable = ['accountant_id', 'restaurant_id', 'module_keys', 'updated_by_id'];

    protected $casts = ['module_keys' => 'array'];

    public function restaurant()
    {
        return $this->belongsTo(AsabRestaurant::class, 'restaurant_id');
    }
}
