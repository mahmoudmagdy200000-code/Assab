<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class BrandOwner extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'brand_owners';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'image',
        'is_active',
        'is_first_login',
        'status',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
        'is_first_login'    => 'boolean',
    ];

    public function isActive(): bool
    {
        return $this->is_active && $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isFirstLogin(): bool
    {
        return (bool) $this->is_first_login;
    }

    public function markFirstLoginComplete(): void
    {
        $this->update(['is_first_login' => false]);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/' . $this->image) : null;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
