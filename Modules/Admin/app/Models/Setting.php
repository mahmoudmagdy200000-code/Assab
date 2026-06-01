<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasUuids;

    protected $table = 'asab_settings';

    protected $fillable = ['company_id', 'group_key', 'payload'];

    protected $casts = ['payload' => 'array'];
}
