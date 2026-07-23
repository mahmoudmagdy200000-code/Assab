<?php

namespace Modules\BrandOwner\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved item profitability test (Menu Engineering → Item Test).
 *
 * `brand_owner_id` holds whoever created the test — a brand owner or a branch
 * manager. Saved-tests listings are filtered by that id so each user only sees
 * their own tests.
 */
class BrandOwnerItemTest extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'brand_owner_item_tests';

    protected $fillable = [
        'brand_owner_id',
        'branch_id',
        'branch_name',
        'item_name',
        'expected_selling_price',
        'production_cost',
        'expected_sales',
        'expected_growth',
        'profit_margin',
        'expected_monthly_profit',
        'expected_classification',
        'menu_impact',
    ];

    protected $casts = [
        'expected_selling_price' => 'decimal:2',
        'production_cost' => 'decimal:2',
        'expected_sales' => 'integer',
        'expected_growth' => 'decimal:2',
        'profit_margin' => 'decimal:2',
        'expected_monthly_profit' => 'decimal:2',
    ];
}
