<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Services\IdentityMapService;
use Modules\Admin\Services\ManagerBranchSyncService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Meeting 2026-08-03: every branch already assigned on the dashboard BEFORE the
 * assignment paths learned to sync the mobile login is still mismatched in
 * production — the manager opens the app and sees another branch's inventory,
 * waste reports and assets. This repairs the existing rows; the controllers
 * keep them in step from now on.
 *
 * Authority is the DASHBOARD (`branches.asab_manager_user_id`), because that is
 * where the assignment is made and reviewed.
 */
class SyncManagerBranchesCommand extends Command
{
    protected $signature = 'asab:sync-manager-branches
        {--dry-run : List the mismatches without repointing anything}';

    protected $description = 'Repoint mobile branch-manager logins at the branch the dashboard assigned them to';

    public function handle(ManagerBranchSyncService $sync, IdentityMapService $identity): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $fixed = 0;

        Branch::query()
            ->whereNotNull('asab_manager_user_id')
            ->orderBy('name')
            ->chunkById(200, function ($chunk) use ($sync, $identity, $dry, &$rows, &$fixed) {
                foreach ($chunk as $branch) {
                    $legacyId = $identity->legacyIdFor(
                        AsabIdentityMap::ENTITY_BRANCH_MANAGER,
                        $branch->asab_manager_user_id,
                    );

                    if ($legacyId === null) {
                        continue; // no mobile login provisioned for this user yet
                    }

                    $manager = BranchManager::find($legacyId);
                    if ($manager === null || $manager->branch_id === $branch->id) {
                        continue;
                    }

                    $rows[] = [$manager->email ?? $manager->id, $manager->branch_id ?? '—', $branch->id.' ('.$branch->name.')'];

                    if (! $dry && $sync->sync($branch->asab_manager_user_id, $branch->id)) {
                        $fixed++;
                    }
                }
            });

        if ($rows === []) {
            $this->info('Every provisioned branch-manager login already points at its dashboard branch.');

            return self::SUCCESS;
        }

        $this->table(['manager', 'mobile branch (was)', 'dashboard branch (now)'], $rows);
        $this->info($dry
            ? count($rows).' mismatch(es) — re-run without --dry-run to repoint.'
            : "Repointed {$fixed} branch-manager login(s).");

        return self::SUCCESS;
    }
}
