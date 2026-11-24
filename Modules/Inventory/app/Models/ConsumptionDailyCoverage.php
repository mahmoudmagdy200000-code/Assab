<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumptionDailyCoverage extends Model
{
    use HasUuids;

    protected $table = 'consumption_daily_coverages';

    protected $fillable = [
        'company_id',
        'source_system',
        'branch_id',
        'branch_code',
        'business_date',
        'revision',
        'day_status',
        'includes_waste',
        'source_ref',
        'content_hash',
        'status',
        'batch_id',
        'confirmed_by_id',
        'confirmed_at',
    ];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'revision' => 'integer',
        'includes_waste' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ConsumptionDailyLine::class, 'coverage_id');
    }
}
