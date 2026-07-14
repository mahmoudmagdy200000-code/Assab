<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cross-world identity link (WS2): maps a dashboard entity to its legacy mobile
 * row. Deliberately NOT tenant-scoped (no BelongsToTenant): its lookups are by
 * unique (entity_type, dashboard_id)/(entity_type, legacy_id) keys, and the one
 * reverse consumer runs in a queued/no-tenant context — a global-scope read
 * there would silently hide rows. company_id is carried as data, not a scope.
 */
class AsabIdentityMap extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'asab_identity_map';

    public const ENTITY_CASHIER = 'cashier';

    public const ENTITY_SUPPLIER = 'supplier';

    public const ENTITY_BRAND_OWNER = 'brand_owner';

    protected $fillable = [
        'company_id', 'entity_type', 'dashboard_type', 'dashboard_id',
        'legacy_type', 'legacy_id', 'match_method', 'linked_email', 'source', 'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];
}
