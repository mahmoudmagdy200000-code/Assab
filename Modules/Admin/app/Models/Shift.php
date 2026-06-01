<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class Shift extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_shifts';

    protected $fillable = [
        'company_id', 'branch_id', 'supervisor_user_id', 'supervisor_name', 'started_at', 'ended_at',
        'status', 'orders_count', 'sales_amount', 'cash_expected', 'cash_actual', 'variance', 'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'orders_count' => 'integer',
        'sales_amount' => 'integer',
        'cash_expected' => 'integer',
        'cash_actual' => 'integer',
        'variance' => 'integer',
    ];
}
