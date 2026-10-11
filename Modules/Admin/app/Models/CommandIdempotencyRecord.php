<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommandIdempotencyRecord extends Model
{
    use HasUuids;

    protected $table = 'asab_command_idempotency_keys';

    protected $guarded = [];

    protected $casts = [
        'response_headers' => 'array',
        'response_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
