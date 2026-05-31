<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AsabRole extends Model
{
    use HasUuids;

    protected $table = 'asab_roles';

    protected $fillable = ['key', 'name_ar', 'name_en'];
}
