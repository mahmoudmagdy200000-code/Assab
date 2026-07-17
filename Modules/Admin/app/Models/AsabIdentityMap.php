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

    /** asab_supplier -> supplier: the commercial record, NOT a login. */
    public const ENTITY_SUPPLIER = 'supplier';

    public const ENTITY_BRAND_OWNER = 'brand_owner';

    /**
     * asab_user -> supplier: the supplier's LOGIN, deliberately a different
     * entity type from ENTITY_SUPPLIER. Both unique indexes are composite with
     * entity_type leading, so the two may name the same legacy supplier without
     * colliding — which they must, since one row keys the commercial record
     * (many asab_suppliers may share an AsabUser) and this one keys the
     * credential (exactly one AsabUser per legacy supplier).
     */
    public const ENTITY_SUPPLIER_USER = 'supplier_user';

    /** asab_user -> branch_manager: the branch manager's login. */
    public const ENTITY_BRANCH_MANAGER = 'branch_manager';

    protected $fillable = [
        'company_id', 'entity_type', 'dashboard_type', 'dashboard_id',
        'legacy_type', 'legacy_id', 'match_method', 'linked_email', 'source', 'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];
}
