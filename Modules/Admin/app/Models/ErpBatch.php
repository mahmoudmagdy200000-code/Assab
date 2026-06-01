<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ErpBatch extends Model
{
    use HasUuids;

    protected $table = 'asab_erp_batches';

    protected $fillable = [
        'batch_id', 'company_id', 'initiated_by_id', 'operation_count', 'total_amount',
        'status', 'filters', 'branch_count', 'erp_response', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'erp_response' => 'array',
        'operation_count' => 'integer',
        'total_amount' => 'integer',
        'branch_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
