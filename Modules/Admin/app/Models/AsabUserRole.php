<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AsabUserRole extends Model
{
    use HasUuids;

    protected $table = 'asab_user_roles';

    protected $fillable = [
        'user_id', 'role_key', 'scope',
        'brand_ids', 'restaurant_ids', 'branch_ids', 'module_keys',
    ];

    protected $casts = [
        'brand_ids' => 'array',
        'restaurant_ids' => 'array',
        'branch_ids' => 'array',
        'module_keys' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(AsabUser::class, 'user_id');
    }
}
