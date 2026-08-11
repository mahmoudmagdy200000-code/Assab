<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Branch\Models\Branch;

/**
 * «كشف حساب الموظفين غير صحيح: أسماء مكررة، ومدراء الفروع غير موجودين»
 * (2026-08-06).
 *
 * Two defects, one repair pass:
 *
 *  1. The roster importer used to `create()` every row, so «حمّل → صحّح →
 *     ارفع» — the documented edit path, since the template ships the saved
 *     rows — minted a fresh employee each time. The importer is idempotent now;
 *     this cleans up what the old one left behind.
 *  2. Branch managers are provisioned as dashboard users and mobile logins, but
 *     never as `asab_employees`, so they were missing from the accountant's
 *     statement list entirely.
 *
 * Safety: a duplicate that carries LEDGER MOVEMENTS is never touched — merging
 * balances is a business decision, not a script's. Those are listed instead.
 */
class RepairEmployeesCommand extends Command
{
    protected $signature = 'asab:repair-employees
        {--dry-run : Report what would change without writing}
        {--skip-managers : Only de-duplicate; do not create employee rows for branch managers}
        {--undo-managers : Remove the manager rows this command created (only untouched ones)}
        {--brand= : Limit every pass to one brand id (its branches, direct or via restaurant)}
        {--branch= : Limit every pass to a single branch id}';

    protected $description = 'De-duplicate uploaded employees and give every branch manager an employee record';

    /** @var string[]|null branch ids the run is limited to, null = every branch */
    private ?array $scope = null;

    public function __construct(
        private readonly \Modules\Admin\Services\ManagerRosterService $roster,
        private readonly \Modules\Admin\Services\IdentityMapService $identity,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Demo brands share the database with the live ones on this deployment,
        // so an unscoped pass adds «مدير برجر بيت — فرع العليا» rows to the
        // accountant's list — and an unscoped undo then removes the REAL
        // managers along with them (2026-08-06). Scope the run instead.
        $this->scope = $this->resolveScope();
        if ($this->scope !== null) {
            if ($this->scope === []) {
                $this->error('No branch matched --brand/--branch.');

                return self::FAILURE;
            }
            $this->info('Scoped to '.count($this->scope).' branch(es).');
        }

        if ($this->option('undo-managers')) {
            $this->undoManagers($dry);

            if ($dry) {
                $this->comment('Dry run — nothing was written.');
            }

            return self::SUCCESS;
        }

        $this->dedupe($dry);

        if (! $this->option('skip-managers')) {
            $this->syncManagers($dry);
        }

        if ($dry) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]|null branch ids for --brand/--branch, null when unscoped
     */
    private function resolveScope(): ?array
    {
        if ($branchId = $this->option('branch')) {
            return Branch::where('id', $branchId)->pluck('id')->all();
        }

        if ($brandId = $this->option('brand')) {
            $restaurantIds = \Modules\Admin\Models\AsabRestaurant::withoutGlobalScopes()
                ->where('brand_id', $brandId)->pluck('id')->all();

            return Branch::where(function ($q) use ($brandId, $restaurantIds) {
                $q->where('asab_brand_id', $brandId);
                if ($restaurantIds !== []) {
                    $q->orWhereIn('asab_restaurant_id', $restaurantIds);
                }
            })->pluck('id')->all();
        }

        return null;
    }

    /**
     * Identity is the national id when present, else the name within the
     * branch — the same key the importer now writes through.
     */
    private function dedupe(bool $dry): void
    {
        $removed = 0;
        $kept = 0;

        Employee::withoutGlobalScopes()
            // withoutGlobalScopes() drops the SoftDeletingScope too — without
            // this, an already-deleted row could be chosen as «the original»
            // and the live duplicates deleted in its place.
            ->whereNull('deleted_at')
            ->when($this->scope !== null, fn ($q) => $q->whereIn('branch_id', $this->scope))
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Employee $e) => implode('|', [
                $e->company_id,
                $e->branch_id ?? '-',
                trim((string) $e->national_id) !== '' ? 'nid:'.$e->national_id : 'name:'.trim((string) $e->name),
            ]))
            ->each(function ($group) use ($dry, &$removed, &$kept) {
                if ($group->count() < 2) {
                    return;
                }

                // The oldest row is the original: its emp_number is the one that
                // already appears on statements and payslips.
                $original = $group->first();

                foreach ($group->skip(1) as $duplicate) {
                    $movements = EmployeeMovement::where('employee_id', $duplicate->id)->count();

                    if ($movements > 0) {
                        $this->warn("  ⚠ {$duplicate->name} ({$duplicate->emp_number}) — {$movements} حركة على النسخة المكررة، تُركت للمراجعة اليدوية");
                        $kept++;

                        continue;
                    }

                    $this->line(($dry ? '[dry] ' : '').
                        "حذف نسخة مكررة: {$duplicate->name} ({$duplicate->emp_number}) — الأصل {$original->emp_number}");

                    if (! $dry) {
                        $duplicate->delete(); // soft delete — recoverable
                    }
                    $removed++;
                }
            });

        $this->info(($dry ? 'Would remove ' : 'Removed ')."{$removed} duplicate employee row(s); kept {$kept} with movements.");
    }

    /**
     * A branch manager belongs on the roster: they hold custody, submit
     * expenses and carry a balance like any other employee.
     *
     * TWO sources, because they disagree in production: the dashboard column
     * `branches.asab_manager_user_id`, and the mobile login table
     * `branch_managers.branch_id` — the one the app itself scopes by. A branch
     * whose manager predates the dashboard has the second only, which is why
     * the first pass over prod created 0 records for branches that plainly had
     * managers (2026-08-06).
     */
    private function syncManagers(bool $dry): void
    {
        $created = 0;

        // 1) The mobile logins — the authoritative «who runs this branch».
        \Modules\BranchManagers\Models\BranchManager::whereNotNull('branch_id')
            ->when($this->scope !== null, fn ($q) => $q->whereIn('branch_id', $this->scope))
            ->get(['id', 'name', 'phone', 'branch_id'])
            ->each(function ($manager) use ($dry, &$created) {
                $branch = Branch::find($manager->branch_id);
                if ($branch === null || $branch->asab_company_id === null) {
                    return;
                }

                $created += $this->ensureManagerEmployee(
                    $branch,
                    (string) $manager->name,
                    $manager->phone,
                    // The dashboard account behind this mobile login, when the
                    // two are linked — that is what stamps the row so a later
                    // transfer moves it instead of losing it.
                    $this->identity->dashboardIdFor(
                        \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRANCH_MANAGER,
                        (string) $manager->id,
                    ),
                    $manager->created_at,
                    $dry,
                ) ? 1 : 0;
            });

        // 2) …and the dashboard assignment, for a branch whose manager has no
        // mobile login yet.
        Branch::whereNotNull('asab_manager_user_id')
            ->when($this->scope !== null, fn ($q) => $q->whereIn('id', $this->scope))
            ->get(['id', 'name', 'asab_company_id', 'asab_manager_user_id'])
            ->each(function (Branch $branch) use ($dry, &$created) {
                $user = AsabUser::withoutGlobalScopes()->find($branch->asab_manager_user_id);
                if ($user === null || $branch->asab_company_id === null) {
                    return;
                }

                $created += $this->ensureManagerEmployee(
                    $branch,
                    (string) $user->name,
                    $user->phone,
                    $branch->asab_manager_user_id,
                    $user->created_at,
                    $dry,
                ) ? 1 : 0;
            });

        $this->info(($dry ? 'Would create ' : 'Created ')."{$created} manager employee record(s).");
    }

    /**
     * The escape hatch for the pass above: seeded/demo manager logins («مدير
     * برجر بيت — فرع العليا») become roster rows too, and an accountant may not
     * want them on the statement list. Only rows this command could have
     * created are eligible — role «مدير فرع», zero salary, and NO ledger
     * movements — so an uploaded or hand-edited manager is never removed.
     */
    private function undoManagers(bool $dry): void
    {
        $removed = 0;

        Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('role', 'مدير فرع')
            ->where('monthly_salary', 0)
            ->when($this->scope !== null, fn ($q) => $q->whereIn('branch_id', $this->scope))
            ->get()
            ->each(function (Employee $employee) use ($dry, &$removed) {
                if (EmployeeMovement::where('employee_id', $employee->id)->exists()) {
                    $this->warn("  ⚠ {$employee->name} — عليه حركات، لم يُحذف");

                    return;
                }

                $this->line(($dry ? '[dry] ' : '')."حذف صف مدير: {$employee->name} ({$employee->emp_number})");
                $removed++;

                if (! $dry) {
                    $employee->delete();
                }
            });

        $this->info(($dry ? 'Would remove ' : 'Removed ')."{$removed} manager employee record(s).");
    }

    /**
     * @param  \DateTimeInterface|null  $hiredAt
     * @return bool whether a row was (or would be) created or MOVED
     */
    private function ensureManagerEmployee(
        Branch $branch,
        string $name,
        ?string $phone,
        ?string $asabUserId,
        $hiredAt,
        bool $dry,
    ): bool {
        if (trim($name) === '') {
            return false;
        }

        $existing = Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $branch->asab_company_id)
            ->where('branch_id', $branch->id)
            ->where(fn ($q) => $q->where('name', $name)->when($phone, fn ($w) => $w->orWhere('phone', $phone)))
            ->first();

        // Already on this branch: nothing to create, but stamp the dashboard
        // link so the NEXT transfer moves this row instead of stranding it.
        if ($existing !== null) {
            if (! $dry && $asabUserId !== null && $existing->asab_user_id !== $asabUserId) {
                $existing->forceFill(['asab_user_id' => $asabUserId])->save();
            }

            return false;
        }

        $this->line(($dry ? '[dry] ' : '')."إضافة مدير الفرع للكشف: {$name} — {$branch->name}");

        if (! $dry) {
            // Delegate: the service adopts a row this manager already has on a
            // PREVIOUS branch and moves it, rather than minting a duplicate
            // that splits their ledger across two branches (2026-08-10).
            $this->roster->ensure($branch, $name, $phone, $asabUserId, $hiredAt);
        }

        return true;
    }
}
