<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryCatalogItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'asab_inventory_catalog';

    protected $fillable = ['brand_id', 'name', 'category', 'unit', 'status'];
}
