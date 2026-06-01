<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Membership of a user within a company + their role — COMPANY_DASHBOARD_API_SPEC.md §3.4. */
class CompanyUser extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_company_users';

    protected $fillable = [
        'company_id', 'user_id', 'role_key', 'brand_id', 'branch_id', 'status',
        'last_seen_at', 'invited_by_id', 'invited_at', 'accepted_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime', 'invited_at' => 'datetime', 'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(AsabUser::class, 'user_id');
    }
}
