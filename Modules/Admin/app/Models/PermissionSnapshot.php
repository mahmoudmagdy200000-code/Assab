<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Immutable point-in-time copy of the permission matrix (FE completion request §2.3). */
class PermissionSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'asab_permission_snapshots';

    protected $fillable = [
        'company_id', 'saved_by_id', 'saved_by_name', 'snapshot', 'changes_count', 'summary_ar', 'created_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'changes_count' => 'integer',
        'created_at' => 'datetime',
    ];
}
