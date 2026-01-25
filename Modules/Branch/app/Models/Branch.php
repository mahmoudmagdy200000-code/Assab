<?php

namespace Modules\Branch\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Aggregator\Models\Aggregator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\Shift;
use Modules\Purchase\Models\BranchItem;
class Branch extends Model
{
    use HasFactory, HasUuids;


    protected $fillable = [
        'name',
        'lat',
        'lng',
        'image',
        'opening_hours',
        'closing_hours',
        'branch_manager_id',
        'branch_manager_image',
    ];

    protected $casts = [
        'lat' => 'decimal:8',
        'lng' => 'decimal:8',
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

    public function branchManager()
    {
        return $this->belongsTo(BranchManager::class, 'branch_manager_id');
    }
    public function branchItems()
    {
        return $this->hasMany(BranchItem::class);
    }
}
