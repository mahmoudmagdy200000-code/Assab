<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmployeeMovement extends Model
{
    use HasUuids;

    protected $table = 'asab_employee_movements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'employee_id', 'movement_date', 'description', 'movement_type', 'category', 'ref', 'amount', 'ref_operation_id', 'created_by_id', 'lineage_key', 'posting_id',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
        'amount' => 'integer',
    ];
}
