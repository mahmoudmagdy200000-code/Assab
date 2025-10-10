<?php

namespace Modules\Cashier\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftVarianceDetail;

class Cashier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'image',
        'branch_id',
        'status',
        'created_by',
        'activated_at',
        'deactivated_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
    }

    public function shifts()
    {
        return $this->hasMany(CashierShift::class);
    }

    public function pendingShifts()
    {
        return $this->shifts()->notStarted()->upcoming();
    }

    public function inProgressShifts()
    {
        return $this->shifts()->inProgress()->today();
    }

    public function completedShifts()
    {
        return $this->shifts()->completed();
    }

    public function reassignedShifts()
    {
        return $this->shifts()->reassigned();
    }

    public function nextShifts()
    {
        return $this->hasMany(CashierShift::class, 'next_cashier_id');
    }

    public function varianceDetails()
    {
        return $this->hasMany(ShiftVarianceDetail::class, 'responsible_cashier_id');
    }

    public function reviewedHandovers()
    {
        return $this->hasMany(ShiftHandoverStatus::class, 'reviewed_by');
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isDeactivated()
    {
        return $this->status === 'deactivated';
    }
}
