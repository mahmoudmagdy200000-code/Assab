<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
 * Unlinked dashboard users have no mobile row to move. A linked manager's
 * branch assignment is required: an occupied destination must fail the whole
 * dashboard/mobile assignment rather than leave divergent branch identities.
 */
class ManagerBranchSyncService
{
    public function __construct(
        private readonly IdentityMapService $identity,
        private readonly ManagerRosterService $roster,
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
            return DB::transaction(function () use ($asabUserId, $branchId): bool {
                Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
                $this->assertDestinationAvailable($asabUserId, $branchId);
                $this->roster->sync($asabUserId, $branchId);

                $legacyId = $this->identity->legacyIdFor(AsabIdentityMap::ENTITY_BRANCH_MANAGER, $asabUserId);
                $manager = $legacyId ? BranchManager::find($legacyId) : null;
                if ($manager === null || $manager->branch_id === $branchId) {
                    return false;
                }

                $previous = $manager->branch_id;
                $manager->forceFill(['branch_id' => $branchId])->save();
                $this->log->info('manager-branch-sync: legacy branch repointed', [
                    'asab_user_id' => $asabUserId,
                    'branch_manager_id' => $manager->id,
                    'from_branch_id' => $previous,
                    'to_branch_id' => $branchId,
                ]);

                return true;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (ConflictHttpException $e) {
            if ($e->getMessage() === 'MANAGER_HAS_OPEN_HANDOVERS') {
                throw new AsabException(
                    'MANAGER_HAS_OPEN_HANDOVERS',
                    'Manager has open addressed handovers',
                    'لدى مدير الفرع عمليات تسليم مفتوحة',
                    409,
                );
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->log->warning('manager-branch-sync: failed', [
                'asab_user_id' => $asabUserId, 'branch_id' => $branchId, 'error' => $e->getMessage(),
            ]);

            throw new AsabException('MANAGER_BRANCH_SYNC_FAILED', 'Manager branch assignment failed', 'تعذر تعيين مدير الفرع', 500);
        }
    }

    public function assertDestinationAvailable(?string $asabUserId, ?string $branchId): void
    {
        if ($asabUserId === null || $branchId === null) {
            return;
        }

        $legacyId = $this->identity->legacyIdFor(AsabIdentityMap::ENTITY_BRANCH_MANAGER, $asabUserId);
        $manager = $legacyId ? BranchManager::find($legacyId) : null;
        if ($manager === null || $manager->branch_id === $branchId) {
            return;
        }

        $candidate = clone $manager;
        $candidate->branch_id = $branchId;
        app(BranchManagerService::class)->assertActiveAssignmentAvailable($candidate);
    }
}
