<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    use HasUuids;

    protected $table = 'asab_cash_transactions';

    protected $fillable = [
        'custody_id', 'txn_date', 'description', 'txn_type', 'amount', 'status', 'reason', 'source', 'created_by_id',
    ];

    protected $casts = [
        'txn_date' => 'datetime',
        'amount' => 'integer',
    ];
}
