<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'asab_supplier_items';

    protected $fillable = [
        'company_id', 'brand_id', 'supplier_user_id', 'supplier_id', 'category', 'code', 'name', 'unit', 'price', 'min_qty', 'status',
        'max_qty', 'available', 'lead_time_days',
    ];

    protected $casts = [
        'price' => 'integer',
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'available' => 'boolean',
        'lead_time_days' => 'integer',
    ];
}
