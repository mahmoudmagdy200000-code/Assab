<?php

namespace Modules\BranchManagers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class BranchManager extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'is_first_login',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_first_login' => 'boolean',
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
    ];

    /**
     * Check if this is the first login
     */
    public function isFirstLogin(): bool
    {
        return $this->is_first_login;
    }

    /**
     * Mark first login as complete
     */
    public function markFirstLoginComplete(): void
    {
        $this->update(['is_first_login' => false]);
    }

    /**
     * Check if account is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }
}
