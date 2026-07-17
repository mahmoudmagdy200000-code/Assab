<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class Asset extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_assets';

    protected $fillable = [
        'company_id', 'public_id', 'name', 'category', 'branch_id', 'zone', 'cost', 'book_value',
        'useful_life_months', 'case_type', 'status', 'inv_num', 'serial', 'submitted_by_id',
        'custodian', 'purchased_at', 'notes', 'quantity', 'qty_excellent', 'qty_maintenance', 'qty_problem',
    ];

    protected $casts = [
        'cost' => 'integer',
        'book_value' => 'integer',
        'useful_life_months' => 'integer',
        'purchased_at' => 'datetime',
        'quantity' => 'integer',
        'qty_excellent' => 'integer',
        'qty_maintenance' => 'integer',
        'qty_problem' => 'integer',
    ];
}
