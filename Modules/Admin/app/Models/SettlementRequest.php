<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SettlementRequest extends Model
{
    use HasUuids;

    protected $table = 'asab_settlement_requests';

    protected $fillable = ['custody_id', 'requested_by_id', 'status', 'requested_at', 'approved_at'];

    protected $casts = ['requested_at' => 'datetime', 'approved_at' => 'datetime'];
}
