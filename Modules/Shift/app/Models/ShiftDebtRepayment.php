<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

/**
 * CASH-16 / BE-16: Cashier debt repayment handover model.
 * Records repayment of debt/deficit without reopening closed shifts or creating phantom sales.
 */
class ShiftDebtRepayment extends Model
{
    use HasUuids;

    protected $table = 'shift_debt_repayments';

    protected $fillable = [
        'company_id',
        'branch_id',
        'cashier_id',
        'manager_id',
        'amount_halalas',
        'notes',
        'status',
        'confirmed_at',
        'confirmed_by_id',
    ];

    protected $casts = [
        'amount_halalas' => 'integer',
        'confirmed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'cashier_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'manager_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'confirmed_by_id');
    }
}
