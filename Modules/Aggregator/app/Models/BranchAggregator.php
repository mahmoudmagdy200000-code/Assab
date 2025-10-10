<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class BranchAggregator extends Model
{
    protected $fillable = [
        'branch_id',
        'aggregator_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function aggregator()
    {
        return $this->belongsTo(Aggregator::class);
    }
}
