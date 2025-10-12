<?php

namespace Modules\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Aggregator\Models\Aggregator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\Shift;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'location',
        'image',
        'opening_hours',
        'map_coordinates',
    ];

    public function managers()
    {
        return $this->hasMany(BranchManager::class);
    }

    public function cashiers()
    {
        return $this->hasMany(Cashier::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function aggregators()
    {
        return $this->belongsToMany(
            Aggregator::class,
            'branch_aggregators'
        )->withPivot('is_enabled')->withTimestamps();
    }

    public function enabledAggregators()
    {
        return $this->aggregators()->wherePivot('is_enabled', true);
    }

    protected static function newFactory()
    {
        return \Modules\Branch\Database\Factories\BranchFactory::new();
    }
}
