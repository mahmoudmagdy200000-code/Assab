<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PermissionMatrixEntry extends Model
{
    use HasUuids;

    protected $table = 'asab_permission_matrix';

    protected $fillable = ['company_id', 'role_key', 'module', 'permission', 'updated_by_id'];
}
