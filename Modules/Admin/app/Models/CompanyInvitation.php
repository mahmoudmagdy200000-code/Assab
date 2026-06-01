<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Pending invite for a user to join a company — COMPANY_DASHBOARD_API_SPEC.md §4.2. */
class CompanyInvitation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_company_invitations';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'email', 'name', 'role_key', 'brand_id', 'branch_id', 'token',
        'status', 'invited_by_id', 'expires_at', 'accepted_at', 'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'created_at' => 'datetime',
    ];
}
