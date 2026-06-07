<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user saved filter preset for a list page (MISSING_Dashboard §11.1).
 */
class SavedFilter extends Model
{
    use HasUuids;

    protected $table = 'asab_saved_filters';

    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'page', 'params', 'created_at'];

    protected $casts = [
        'params' => 'array',
        'created_at' => 'datetime',
    ];
}
