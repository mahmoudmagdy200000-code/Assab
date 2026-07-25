<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Admin\Models\Concerns\BelongsToTenant;

/**
 * A supplier record. `company_id` is NULLABLE and that null is meaningful:
 *
 *  - `company_id = null` — a PLATFORM supplier. Contracts with ASAB itself and
 *    trades with every company; visible to all of them.
 *  - `company_id = <uuid>` — a supplier that one company added for itself.
 *    Unchanged behaviour, still tenant-isolated.
 */
class AsabSupplier extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Platform suppliers read their own row (scoped by user_id) across companies. */
    protected static function platformVisible(): bool
    {
        return true;
    }

    /** ...and every company reads platform suppliers, or it could never order from one. */
    protected static function tenantSharesPlatformRows(): bool
    {
        return true;
    }

    protected $table = 'asab_suppliers';

    protected $fillable = [
        'company_id', 'brand_id', 'name', 'code', 'category', 'contact_name', 'contact_phone',
        'contact_email', 'commercial_reg', 'payment_terms', 'user_id', 'rating', 'status', 'is_external',
    ];

    protected $casts = ['rating' => 'integer', 'is_external' => 'boolean'];
}
