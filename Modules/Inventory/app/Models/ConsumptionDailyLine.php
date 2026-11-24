<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConsumptionDailyLine extends Model
{
    use HasUuids;

    protected $table = 'consumption_daily_lines';

    protected $fillable = [
        'coverage_id',
        'company_id',
        'branch_id',
        'business_date',
        'item_id',
        'item_code',
        'unit_code',
        'consumed_qty',
        'included_waste_qty',
        'normal_consumed_qty',
    ];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'consumed_qty' => 'decimal:3',
        'included_waste_qty' => 'decimal:3',
        'normal_consumed_qty' => 'decimal:3',
    ];
}
