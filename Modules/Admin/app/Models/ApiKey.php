<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** 3rd-party integration API key (FE completion request §3.3). Only the hash is stored. */
class ApiKey extends Model
{
    use HasUuids;

    protected $table = 'asab_api_keys';

    protected $fillable = [
        'company_id', 'name', 'prefix', 'key_hash', 'scopes',
        'last_used_at', 'expires_at', 'revoked_at', 'created_by_id',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
