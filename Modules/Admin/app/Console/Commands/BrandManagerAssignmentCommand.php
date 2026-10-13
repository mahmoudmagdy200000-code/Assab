<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\BrandManagerAssignmentService;

/**
 * D-2 admin provisioning for brand-manager delegations (no public endpoint).
 *
 *   php artisan asab:brand-manager grant  {brandManagerId} --dashboard-user=UUID --brands=UUID --brands=UUID [--by=UUID]
 *   php artisan asab:brand-manager revoke {brandManagerId} [--by=UUID]
 *
 * The grantor-authority rule (which actor may delegate, and the owner approval
 * it requires) is a business decision still owned by Mahmoud/Mohamed; until it
 * is fixed, delegation is performed here by an operator, auditably, rather than
 * through an invented owner-facing endpoint.
 */
class BrandManagerAssignmentCommand extends Command
{
    protected $signature = 'asab:brand-manager {action : grant|revoke} {manager : mobile BrandManager id}
        {--dashboard-user= : AsabUser id (required for grant)}
        {--brands=* : delegated brand ids (grant)}
        {--by= : acting dashboard user id, for audit}';

    protected $description = 'Grant / change / revoke an explicit brand-manager brand delegation (D-2).';

    public function handle(BrandManagerAssignmentService $service): int
    {
        $action = (string) $this->argument('action');
        $managerId = (string) $this->argument('manager');
        $by = $this->option('by') ?: null;

        if ($action === 'revoke') {
            $service->revoke($managerId, $by);
            $this->info("Revoked brand-manager delegation for {$managerId}.");

            return self::SUCCESS;
        }

        if ($action !== 'grant') {
            $this->error("Unknown action '{$action}'. Use grant or revoke.");

            return self::INVALID;
        }

        $userId = $this->option('dashboard-user');
        if (! $userId) {
            $this->error('--dashboard-user is required for grant.');

            return self::INVALID;
        }

        $user = AsabUser::withoutGlobalScope('tenant')->find($userId);
        if (! $user) {
            $this->error("Dashboard user {$userId} not found.");

            return self::FAILURE;
        }

        try {
            $service->grant($managerId, $user, (array) $this->option('brands'), $by);
        } catch (\RuntimeException $e) {
            $this->error('Grant refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Granted brand-manager delegation for {$managerId}.");

        return self::SUCCESS;
    }
}
