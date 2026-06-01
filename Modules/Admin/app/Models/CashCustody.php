<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class CashCustody extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_cash_custody';

    protected $fillable = [
        'company_id', 'branch_id', 'custodian_user_id', 'custodian_name', 'amount', 'used',
        'days_since_settlement', 'last_settlement_at', 'status',
    ];

    protected $casts = [
        'amount' => 'integer',
        'used' => 'integer',
        'days_since_settlement' => 'integer',
        'last_settlement_at' => 'datetime',
    ];

    public function transactions()
    {
        return $this->hasMany(CashTransaction::class, 'custody_id')->orderByDesc('txn_date');
    }
}
