<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/** Supplier rating left by procurement/branch — COMPANY_DASHBOARD_API_SPEC.md §5.5. */
class SupplierRating extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asab_supplier_ratings';

    public $timestamps = false;

    protected $fillable = ['company_id', 'supplier_id', 'rater_user_id', 'rating', 'comment', 'created_at'];

    protected $casts = ['rating' => 'integer', 'created_at' => 'datetime'];
}
