<?php

namespace Modules\Admin\Services;

use Modules\Admin\Http\Controllers\Concerns\GeneratesEmployeeNumbers;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

/**
 * «كشف حساب الموظفين: الفلترة بالفرع بتُسقط مدير الفرع» (2026-08-10).
 *
 * A branch manager belongs on the accountant's roster like any other employee —
 * they hold custody, submit expenses and carry a balance. Their
 * `asab_employees` row was created once (by `asab:repair-employees`) at whatever
 * branch they ran at the time, and NOTHING moved it afterwards: assigning or
 * transferring a manager on the dashboard wrote `branches.asab_manager_user_id`,
 * the role scope and the mobile `branch_managers.branch_id`, but never the
 * roster row. `GET /company/me/employees?branchId=X` filters on
 * `asab_employees.branch_id`, so the manager dropped out of their own branch.
 *
 * This service is the missing half. It owns the roster side of «who runs this
 * branch»:
 *
 *  - {@see sync()} — create / adopt / MOVE the manager's row so `branch_id`
 *    always names the branch they currently manage, stamping `asab_user_id` so
 *    the link survives a rename;
 *  - {@see employeesManagingBranch()} — the read-path counterpart, so a branch
 *    filter still finds a manager whose row predates the stamp.
 *
 * Best-effort by design: the dashboard-side assignment must never roll back
 * because a roster row could not be written.
 */
class ManagerRosterService
{
    use GeneratesEmployeeNumbers;

    /** The role label every manager roster row carries (Arabic, as displayed). */
    public const MANAGER_ROLE = 'مدير فرع';

    public function __construct(
        private readonly IdentityMapService $identity,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    /**
     * Point the dashboard user's roster row at `$branchId`, creating or adopting
     * one when needed.
     *
     * @return Employee|null the row, or null when there is nothing to attach it
     *                       to (unknown user/branch, or a branch with no company)
     */
    public function sync(?string $asabUserId, ?string $branchId): ?Employee
    {
        if ($asabUserId === null || $branchId === null) {
            return null;
        }

        try {
            $branch = Branch::find($branchId);
            $user = AsabUser::withoutGlobalScopes()->find($asabUserId);
            if ($branch === null || $user === null || $branch->asab_company_id === null) {
                return null;
            }

            return $this->ensure(
                $branch,
                (string) $user->name,
                $user->phone,
                $asabUserId,
                $user->created_at,
            );
        } catch (\Throwable $e) {
            $this->log->warning('manager-roster-sync: failed', [
                'asab_user_id' => $asabUserId, 'branch_id' => $branchId, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create / adopt / move the roster row for one manager of `$branch`.
     *
     * `$asabUserId` may be null for a manager who exists only as a mobile login
     * (no dashboard account yet) — the row is then matched by name/phone and
     * carries no stamp, which is exactly what {@see employeesManagingBranch()}
     * compensates for on the read path.
     */
    public function ensure(
        Branch $branch,
        string $name,
        ?string $phone,
        ?string $asabUserId = null,
        $hiredAt = null,
    ): ?Employee {
        $name = trim($name);
        if ($name === '' || $branch->asab_company_id === null) {
            return null;
        }

        $existing = $this->findExisting($branch, $name, $phone, $asabUserId);

        if ($existing !== null) {
            $attrs = [];
            // The move that was missing: a transferred manager's ledger, salary
            // and history stay on the SAME row, it just changes branch.
            if ($existing->branch_id !== $branch->id) {
                $this->log->info('manager-roster-sync: employee branch repointed', [
                    'employee_id' => $existing->id, 'asab_user_id' => $asabUserId,
                    'from_branch_id' => $existing->branch_id, 'to_branch_id' => $branch->id,
                ]);
                $attrs['branch_id'] = $branch->id;
            }
            if ($asabUserId !== null && $existing->asab_user_id !== $asabUserId) {
                $attrs['asab_user_id'] = $asabUserId;
            }
            if ($attrs !== []) {
                $existing->forceFill($attrs)->save();
            }

            return $existing;
        }

        return Employee::create([
            'company_id' => $branch->asab_company_id,
            'branch_id' => $branch->id,
            'asab_user_id' => $asabUserId,
            'emp_number' => $this->nextEmpNumber($branch->asab_company_id),
            'name' => $name,
            'phone' => $phone,
            'role' => self::MANAGER_ROLE,
            'monthly_salary' => 0,
            'hire_date' => $hiredAt ?? now(),
            'status' => 'active',
        ]);
    }

    /**
     * Employee ids of the people who CURRENTLY manage `$branchId`, whatever
     * `branch_id` their row still says.
     *
     * The read-path safety net for rows written before the stamp existed: a
     * branch filter runs `branch_id = X OR id IN (…this…)`, so the manager shows
     * up on their branch on a deployment where the backfill has not run yet.
     *
     * @return string[]
     */
    public function employeesManagingBranch(?string $branchId): array
    {
        if ($branchId === null || $branchId === '') {
            return [];
        }

        $branch = Branch::find($branchId);
        if ($branch === null || $branch->asab_company_id === null) {
            return [];
        }

        [$userIds, $identities] = $this->managerIdentitiesOf($branch);
        if ($userIds === [] && $identities === []) {
            return [];
        }

        return Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $branch->asab_company_id)
            ->where(function ($q) use ($userIds, $identities) {
                if ($userIds !== []) {
                    $q->orWhereIn('asab_user_id', $userIds);
                }
                foreach ($identities as [$name, $phone]) {
                    $q->orWhere(function ($w) use ($name, $phone) {
                        $w->where('name', $name);
                        if ($phone !== null && $phone !== '') {
                            $w->orWhere('phone', $phone);
                        }
                    });
                }
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Managed-branch id per dashboard user, for the employee rows on one page.
     * Lets the list present a stamped manager on the branch they actually run
     * even before the backfill has moved their `branch_id`.
     *
     * @param  iterable<?string>  $asabUserIds
     * @return array<string,string> asabUserId → branchId
     */
    public function managedBranchByUser(iterable $asabUserIds): array
    {
        $ids = collect($asabUserIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Branch::whereIn('asab_manager_user_id', $ids)
            ->pluck('id', 'asab_manager_user_id')
            ->all();
    }

    /**
     * The two sources that disagree in production (see RepairEmployeesCommand):
     * the dashboard column `branches.asab_manager_user_id` and the mobile login
     * table `branch_managers.branch_id`.
     *
     * @return array{0: string[], 1: array<int, array{0:string, 1:?string}>} [asab user ids, [name, phone] pairs]
     */
    private function managerIdentitiesOf(Branch $branch): array
    {
        $userIds = [];
        $identities = [];

        if ($branch->asab_manager_user_id !== null) {
            $userIds[] = $branch->asab_manager_user_id;
            $user = AsabUser::withoutGlobalScopes()->find($branch->asab_manager_user_id);
            if ($user !== null && trim((string) $user->name) !== '') {
                $identities[] = [trim((string) $user->name), $user->phone];
            }
        }

        foreach (BranchManager::where('branch_id', $branch->id)->get(['id', 'name', 'phone']) as $manager) {
            if (trim((string) $manager->name) !== '') {
                $identities[] = [trim((string) $manager->name), $manager->phone];
            }
            $dashboardId = $this->identity->dashboardIdFor(AsabIdentityMap::ENTITY_BRANCH_MANAGER, (string) $manager->id);
            if ($dashboardId !== null) {
                $userIds[] = $dashboardId;
            }
        }

        return [array_values(array_unique($userIds)), $identities];
    }

    /**
     * The manager's existing row: by stamp first (exact), then by name/phone
     * within the company — the only link a pre-stamp row carries. Prefers a row
     * already on this branch so a company with two same-named managers is not
     * shuffled between branches.
     */
    private function findExisting(Branch $branch, string $name, ?string $phone, ?string $asabUserId): ?Employee
    {
        $base = fn () => Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $branch->asab_company_id);

        if ($asabUserId !== null) {
            $stamped = $base()->where('asab_user_id', $asabUserId)->first();
            if ($stamped !== null) {
                return $stamped;
            }
        }

        $byIdentity = fn () => $base()->where(function ($q) use ($name, $phone) {
            $q->where('name', $name);
            if ($phone !== null && $phone !== '') {
                $q->orWhere('phone', $phone);
            }
        });

        return $byIdentity()->where('branch_id', $branch->id)->first()
            // A manager who moved: their row is still on the previous branch.
            // Only a MANAGER row is adopted across branches — a plain employee
            // who happens to share the name stays where the roster put them.
            ?? $byIdentity()->where('role', self::MANAGER_ROLE)->first();
    }
}
