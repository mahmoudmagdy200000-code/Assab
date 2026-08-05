<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Support\TenantContext;
use Modules\Branch\Models\Branch;

/**
 * Meeting 2026-08-04: an accountant created before the wizard learned to derive
 * the tenant carries `asab_users.company_id = NULL`. Everything they open then
 * answers «المستخدم غير مرتبط بشركة» (ResolveTenant, WRONG_TENANT) and their
 * brand list is empty — the account looks created but is inert.
 *
 * This fills the column from the scope the account already has: its assigned
 * brands, else restaurants, else branches, else the company of the head it
 * reports to. Only ever fills a NULL, and only when the scope resolves to
 * exactly ONE company — a genuinely ambiguous account is listed for an admin to
 * fix by hand rather than guessed at.
 *
 * Platform roles (supplier / procurement) are skipped: a NULL company is their
 * correct state, see TenantContext::PLATFORM_ROLES.
 */
class RepairUserCompaniesCommand extends Command
{
    protected $signature = 'asab:repair-user-companies
        {--dry-run : List what would be filled, and what stays ambiguous}';

    protected $description = 'Fill asab_users.company_id for accounts whose role scope already names one company';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $filled = [];
        $ambiguous = [];

        AsabUser::query()
            ->whereNull('company_id')
            ->with('roleAssignments')
            ->orderBy('name')
            ->chunkById(200, function ($chunk) use ($dry, &$filled, &$ambiguous) {
                foreach ($chunk as $user) {
                    $roleKey = $user->roleAssignments->first()?->role_key;

                    if ($roleKey === null || $roleKey === 'admin' || in_array($roleKey, TenantContext::PLATFORM_ROLES, true)) {
                        continue;
                    }

                    $companyId = $this->resolve($user);

                    if ($companyId === null) {
                        $ambiguous[] = [$user->email ?? $user->id, $roleKey, 'no single company in scope'];

                        continue;
                    }

                    $filled[] = [$user->email ?? $user->id, $roleKey, $companyId];

                    if (! $dry) {
                        $user->forceFill(['company_id' => $companyId])->save();
                    }
                }
            });

        if ($filled !== []) {
            $this->table(['user', 'role', 'company'], $filled);
        }
        $this->info(($dry ? 'Would fill ' : 'Filled ').count($filled).' account(s).');

        if ($ambiguous !== []) {
            $this->warn(count($ambiguous).' account(s) still have no resolvable company — assign their brands first:');
            $this->table(['user', 'role', 'why'], $ambiguous);
        }

        return self::SUCCESS;
    }

    /** The one company this account's scope points at, or null when unresolvable. */
    private function resolve(AsabUser $user): ?string
    {
        $assignment = $user->roleAssignments->first();

        $candidates = collect();
        if (! empty($assignment?->brand_ids)) {
            $candidates = AsabBrand::withoutGlobalScope('tenant')
                ->whereIn('id', $assignment->brand_ids)->pluck('company_id');
        } elseif (! empty($assignment?->restaurant_ids)) {
            $candidates = AsabRestaurant::withoutGlobalScope('tenant')
                ->whereIn('id', $assignment->restaurant_ids)->pluck('company_id');
        } elseif (! empty($assignment?->branch_ids)) {
            $candidates = Branch::whereIn('id', $assignment->branch_ids)->pluck('asab_company_id');
        } elseif ($user->reports_to_id) {
            // A head's team member inherits the head's tenant — the distribution
            // screen assigns the head before the brands in some flows.
            $candidates = collect([AsabUser::whereKey($user->reports_to_id)->value('company_id')]);
        }

        $resolved = $candidates->filter()->unique()->values();

        return $resolved->count() === 1 ? $resolved->first() : null;
    }
}
