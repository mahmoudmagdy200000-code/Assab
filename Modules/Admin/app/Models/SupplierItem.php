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
        'supplier_user_id', 'code', 'name', 'unit', 'price', 'min_qty', 'status',
    ];

    protected $casts = [
        'price' => 'integer',
        'min_qty' => 'integer',
    ];
}
