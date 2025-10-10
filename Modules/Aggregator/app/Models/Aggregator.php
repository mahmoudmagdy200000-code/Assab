<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\ShiftSalesBreakdown;

class Aggregator extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branches()
    {
        return $this->belongsToMany(
            Branch::class,
            'branch_aggregators'
        )->withPivot('is_enabled')->withTimestamps();
    }

    public function salesBreakdown()
    {
        return $this->hasMany(ShiftSalesBreakdown::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
