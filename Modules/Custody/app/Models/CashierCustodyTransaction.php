<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Cashier\Models\Cashier;

class CashierCustodyTransaction extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'cashier_custody_transactions';

    protected $fillable = [
        'cashier_id',
        'transaction_type',
        'amount',
        'is_cash_in',
        'counterpart_name',
        'related_shift_id',
        'related_handover_id',
        'transaction_date',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'is_cash_in'       => 'boolean',
        'transaction_date' => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class);
    }
}
