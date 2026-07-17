<?php

namespace Modules\Admin\Services\Credentials;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\Supplier\Models\Supplier as LegacySupplier;

/**
 * The mobile app's suppliers world (`supplier` guard). Keyed on
 * ENTITY_SUPPLIER_USER (asab_user -> supplier), never ENTITY_SUPPLIER, which
 * links the commercial record rather than a login.
 */
class SupplierPeer extends EloquentCredentialPeer
{
    public function entityType(): string
    {
        return AsabIdentityMap::ENTITY_SUPPLIER_USER;
    }

    protected function model(): string
    {
        return LegacySupplier::class;
    }
}
