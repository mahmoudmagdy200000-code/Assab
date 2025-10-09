<?php

namespace Modules\Cashier\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class Cashier extends Model
{
    use HasFactory;

    protected $table = 'cashiers';

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

    // Relationships
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
}
