<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Notification\Traits\HasDeviceTokens;

/**
 * The unified ASAB platform user (6 roles: admin, head, accountant, branch,
 * procurement, supplier). Separate from the legacy per-type auth models; uses
 * the `asab` Sanctum guard.
 */
class AsabUser extends Authenticatable
{
    use HasApiTokens, HasDeviceTokens, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'asab_users';

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'password', 'avatar',
        'status', 'reports_to_id', 'default_page', 'last_login_at',
        'two_factor_method', 'two_factor_secret', 'two_factor_backup_codes', 'two_factor_confirmed_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_backup_codes'];

    protected $casts = [
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'two_factor_secret' => 'encrypted',
        'two_factor_backup_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_method !== null;
    }

    public function roleAssignments()
    {
        return $this->hasMany(AsabUserRole::class, 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(AsabCompany::class, 'company_id');
    }

    public function reportsTo()
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /** @return string[] */
    public function roleKeys(): array
    {
        return $this->roleAssignments->pluck('role_key')->all();
    }

    public function hasAsabRole(string $role): bool
    {
        return in_array($role, $this->roleKeys(), true);
    }

    public function hasAnyAsabRole(array $roles): bool
    {
        return count(array_intersect($roles, $this->roleKeys())) > 0;
    }

    public function primaryRole(): ?string
    {
        return $this->roleAssignments->first()->role_key ?? null;
    }
}
