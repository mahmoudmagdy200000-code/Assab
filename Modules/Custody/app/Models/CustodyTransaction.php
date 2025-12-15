<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;

class CustodyTransaction extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'branch_manager_id',
        'branch_id',
        'type',
        'amount',
        'is_cash_in',
        'related_custody_request_id',
        'related_expense_id',
        'related_handover_id',
        'handover_recipient_type',
        'handover_recipient_id',
        'handover_method',
        'handover_date',
        'handover_notes',
        'transaction_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_cash_in' => 'boolean',
        'handover_date' => 'datetime',
        'transaction_date' => 'datetime',
    ];

    // Relationships
    public function branchManager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function custodyRequest(): BelongsTo
    {
        return $this->belongsTo(CustodyRequest::class, 'related_custody_request_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'related_expense_id');
    }
}
