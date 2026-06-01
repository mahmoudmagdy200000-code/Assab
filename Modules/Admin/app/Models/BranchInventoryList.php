<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BranchInventoryList extends Model
{
    use HasUuids;

    protected $table = 'asab_branch_inventory_lists';

    protected $fillable = ['branch_id', 'catalog_item_id', 'is_flagged', 'added_by_id'];

    protected $casts = ['is_flagged' => 'boolean'];
}
