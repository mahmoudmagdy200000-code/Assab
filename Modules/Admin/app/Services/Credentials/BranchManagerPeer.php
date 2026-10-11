<?php

namespace Modules\Admin\Services\Credentials;

use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\BranchManagers\Models\BranchManager;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    public function disable(Model $row): void
    {
        try {
            parent::disable($row);
        } catch (ConflictHttpException $exception) {
            if ($exception->getMessage() === 'MANAGER_HAS_OPEN_HANDOVERS') {
                throw new AsabException(
                    'MANAGER_HAS_OPEN_HANDOVERS',
                    'Manager has open addressed handovers',
                    'لدى مدير الفرع عمليات تسليم مفتوحة',
                    409,
                );
            }

            throw $exception;
        }
    }
}
