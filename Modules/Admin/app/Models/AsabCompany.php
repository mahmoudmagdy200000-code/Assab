<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AsabCompany extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'asab_companies';

    protected $fillable = [
        'name', 'logo', 'contact_name', 'contact_email', 'contact_phone', 'city',
        'plan', 'status', 'max_branches', 'max_users', 'monthly_revenue',
        'start_date', 'next_billing', 'modules', 'admin_email',
    ];

    protected $casts = [
        'modules' => 'array',
        'start_date' => 'datetime',
        'next_billing' => 'datetime',
        'monthly_revenue' => 'integer',
        'max_branches' => 'integer',
        'max_users' => 'integer',
    ];

    public function brands()
    {
        return $this->hasMany(AsabBrand::class, 'company_id');
    }

    public function users()
    {
        return $this->hasMany(AsabUser::class, 'company_id');
    }
}
