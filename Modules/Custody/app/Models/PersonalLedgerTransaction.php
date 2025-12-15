<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BranchManagers\Models\BranchManager;

class PersonalLedgerTransaction extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'branch_manager_id',
        'transaction_type',
        'amount',
        'is_cash_in',
        'cashier_name',
        'brand_owner_name',
        'related_shift_id',
        'related_handover_id',
        'transaction_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_cash_in' => 'boolean',
        'transaction_date' => 'datetime',
    ];

    // Relationships
    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class);
    }
}
