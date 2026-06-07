<?php

namespace Modules\Branch\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Aggregator\Models\Aggregator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Purchase\Models\BranchItem;
use Modules\Shift\Models\Shift;

class Branch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'location',
        'lat',
        'lng',
        'image',
        'opening_hours',
        'closing_hours',
        // ASAB SaaS hierarchy (additive — see add_asab_hierarchy_to_branches migration)
        'phone',
        'email',
        'address',
        'city',
        'is_active',
        'manager',
        'status',
        'asab_company_id',
        'asab_brand_id',
        'asab_restaurant_id',
        'asab_manager_user_id',
        'asab_monthly_target',
    ];

    protected $casts = [
        'lat' => 'decimal:8',
        'lng' => 'decimal:8',
        'opening_hours' => 'datetime',
        'closing_hours' => 'datetime',
        'asab_monthly_target' => 'integer',
    ];

    public function managers()
    {
        return $this->hasMany(BranchManager::class);
    }

    public function branchManager()
    {
        return $this->hasOne(BranchManager::class)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc');
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

    public function branchItems()
    {
        return $this->hasMany(BranchItem::class);
    }
}
