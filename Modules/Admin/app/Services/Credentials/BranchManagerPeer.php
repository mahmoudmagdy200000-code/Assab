<?php

namespace Modules\Admin\Services\Credentials;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\BranchManagers\Models\BranchManager;

/** The mobile app's branch_managers world (Sanctum + the `branch.manager` role). */
class BranchManagerPeer extends EloquentCredentialPeer
{
    public function entityType(): string
    {
        return AsabIdentityMap::ENTITY_BRANCH_MANAGER;
    }

    protected function model(): string
    {
        return BranchManager::class;
    }
}
