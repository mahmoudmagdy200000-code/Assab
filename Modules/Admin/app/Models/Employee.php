<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class Employee extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_employees';

    protected $fillable = [
        'company_id', 'branch_id', 'asab_user_id', 'emp_number', 'name', 'phone', 'national_id', 'role',
        'monthly_salary', 'shift_type', 'hire_date', 'status',
    ];

    protected $casts = [
        'monthly_salary' => 'integer',
        'hire_date' => 'datetime',
    ];
}
