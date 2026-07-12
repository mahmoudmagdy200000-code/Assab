<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryCatalogItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'asab_inventory_catalog';

    public const TYPE_SALES_ITEM = 'sales_item';

    public const TYPE_RAW_MATERIAL = 'raw_material';

    protected $fillable = ['brand_id', 'type', 'name', 'code', 'category', 'unit', 'status', 'unit_price', 'min_level', 'expected_qty'];

    protected $casts = [
        'unit_price' => 'integer',
        'min_level' => 'decimal:3',
        'expected_qty' => 'decimal:3',
    ];
}
