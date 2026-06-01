<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AsabNotification extends Model
{
    use HasUuids;

    protected $table = 'asab_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'link', 'ref_type', 'ref_id', 'read_at',
    ];

    protected $casts = ['read_at' => 'datetime'];
}
