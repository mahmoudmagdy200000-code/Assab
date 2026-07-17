<?php

namespace Modules\Admin\Services\Credentials;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\BrandOwner\Models\BrandOwner as MobileBrandOwner;

/** The mobile app's brand_owners world (`brand_owner` guard). */
class BrandOwnerPeer extends EloquentCredentialPeer
{
    public function entityType(): string
    {
        return AsabIdentityMap::ENTITY_BRAND_OWNER;
    }

    protected function model(): string
    {
        return MobileBrandOwner::class;
    }
}
