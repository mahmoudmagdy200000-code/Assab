<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved price-change simulation scenario (Menu Engineering → Pricing Simulator).
 *
 * `brand_owner_id` holds whoever created the scenario — a brand owner or a branch
 * manager. Saved-scenario listings are filtered by that id so each user only sees
 * their own scenarios.
 */
class BrandOwnerPriceScenario extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'brand_owner_price_scenarios';

    protected $fillable = [
        'brand_owner_id',
        'branch_id',
        'branch_name',
        'item_id',
        'item_name',
        'current_selling_price',
        'production_cost',
        'current_monthly_sales',
        'expected_growth_percentage',
        'new_price',
        'expected_sales',
        'expected_sales_change_percentage',
        'new_unit_profit',
        'new_unit_profit_change_percentage',
        'new_monthly_profit',
        'profit_change',
        'profit_change_percentage',
    ];

    protected $casts = [
        'current_selling_price' => 'decimal:2',
        'production_cost' => 'decimal:2',
        'current_monthly_sales' => 'integer',
        'expected_growth_percentage' => 'decimal:2',
        'new_price' => 'decimal:2',
        'expected_sales' => 'integer',
        'expected_sales_change_percentage' => 'decimal:2',
        'new_unit_profit' => 'decimal:2',
        'new_unit_profit_change_percentage' => 'decimal:2',
        'new_monthly_profit' => 'decimal:2',
        'profit_change' => 'decimal:2',
        'profit_change_percentage' => 'decimal:2',
    ];
}
