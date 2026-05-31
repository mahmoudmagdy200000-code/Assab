<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The unified ASAB platform user (6 roles: admin, head, accountant, branch,
 * procurement, supplier). Separate from the legacy per-type auth models; uses
 * the `asab` Sanctum guard.
 */
class AsabUser extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'asab_users';

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'password', 'avatar',
        'status', 'reports_to_id', 'default_page', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

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
