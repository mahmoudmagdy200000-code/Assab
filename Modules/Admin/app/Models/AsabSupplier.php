<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

class AsabSupplier extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'asab_suppliers';

    protected $fillable = [
        'company_id', 'brand_id', 'name', 'category', 'contact_name', 'contact_phone',
        'contact_email', 'commercial_reg', 'payment_terms', 'user_id', 'rating', 'status',
    ];

    protected $casts = ['rating' => 'integer'];
}
