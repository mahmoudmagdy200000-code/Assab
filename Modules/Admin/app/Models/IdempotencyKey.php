<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUuids;

    protected $table = 'asab_idempotency_keys';

    protected $fillable = ['key', 'user_id', 'method', 'path', 'response_body', 'status', 'expires_at'];

    protected $casts = [
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];
}
