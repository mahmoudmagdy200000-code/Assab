<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssetDraft extends Model
{
    use HasUuids;

    protected $table = 'asab_asset_drafts';

    protected $fillable = [
        'draft_id', 'company_id', 'expense_op_id', 'inv_num', 'vendor', 'desc', 'amount',
        'expense_branch', 'expense_date', 'asset_name', 'category', 'useful_life_months',
        'target_branches', 'custodian', 'qty', 'notes', 'status', 'converted_at', 'created_by_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'useful_life_months' => 'integer',
        'qty' => 'integer',
        'target_branches' => 'array',
        'expense_date' => 'datetime',
        'converted_at' => 'datetime',
    ];
}
