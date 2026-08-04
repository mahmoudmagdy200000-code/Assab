<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Meeting 2026-08-03 «فرع جديد وفيه بيانات قديمة»: assigning a manager to a
 * branch on the dashboard wrote `branches.asab_manager_user_id` and the ASAB
 * role scope — and nothing else. The mobile app scopes EVERY screen by
 * `branch_managers.branch_id`, which only BranchManagerProvisioner ever set, at
 * account-creation time. A manager moved (or a branch created and handed to an
 * existing manager) therefore kept opening their PREVIOUS branch: old inventory
 * sessions, old waste reports, an asset register that belongs to another
 * branch. This service is the missing half — it repoints the legacy row through
 * the identity map.
 *
 * Best-effort by design: an unlinked dashboard user (no mobile login yet) is a
 * no-op, and a failure must never roll back the dashboard-side assignment.
 */
class ManagerBranchSyncService
{
    public function __construct(
        private readonly IdentityMapService $identity,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    /**
     * Point the dashboard user's mobile branch-manager login at `$branchId`.
     *
     * @return bool true when a legacy row was moved
     */
    public function sync(?string $asabUserId, ?string $branchId): bool
    {
        if ($asabUserId === null || $branchId === null) {
            return false;
        }

        try {
            $legacyId = $this->identity->legacyIdFor(AsabIdentityMap::ENTITY_BRANCH_MANAGER, $asabUserId);
            if ($legacyId === null) {
                return false;
            }

            $manager = BranchManager::find($legacyId);
            if ($manager === null || $manager->branch_id === $branchId) {
                return false;
            }

            $previous = $manager->branch_id;
            $manager->forceFill(['branch_id' => $branchId])->save();

            // The move is auditable: the manager's mobile history stays on the
            // old branch, so support needs to know when the cut-over happened.
            $this->log->info('manager-branch-sync: legacy branch repointed', [
                'asab_user_id' => $asabUserId,
                'branch_manager_id' => $manager->id,
                'from_branch_id' => $previous,
                'to_branch_id' => $branchId,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->log->warning('manager-branch-sync: failed', [
                'asab_user_id' => $asabUserId, 'branch_id' => $branchId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
