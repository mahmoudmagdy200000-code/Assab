<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class EmployeeSettlementRequest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_employee_settlement_requests';

    protected $fillable = [
        'company_id',
        'employee_id',
        'amount_halalas',
        'is_full_settlement',
        'original_balance_halalas',
        'direction',
        'reason',
        'status',
        'rejection_reason',
        'created_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'movement_id',
        'idempotency_key',
    ];

    protected $casts = [
        'amount_halalas' => 'integer',
        'is_full_settlement' => 'boolean',
        'original_balance_halalas' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(AsabUser::class, 'created_by_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(AsabUser::class, 'reviewed_by_user_id');
    }

    public function movement()
    {
        return $this->belongsTo(EmployeeMovement::class, 'movement_id');
    }
}
