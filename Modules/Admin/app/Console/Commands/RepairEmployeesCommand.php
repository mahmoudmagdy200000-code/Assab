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
    // Same EMP-#### sequence the upload and branch screens draw from.
    use \Modules\Admin\Http\Controllers\Concerns\GeneratesEmployeeNumbers;

    protected $signature = 'asab:repair-employees
        {--dry-run : Report what would change without writing}
        {--skip-managers : Only de-duplicate; do not create employee rows for branch managers}';

    protected $description = 'De-duplicate uploaded employees and give every branch manager an employee record';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

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
     */
    private function syncManagers(bool $dry): void
    {
        $created = 0;

        Branch::whereNotNull('asab_manager_user_id')
            ->get(['id', 'name', 'asab_company_id', 'asab_manager_user_id'])
            ->each(function (Branch $branch) use ($dry, &$created) {
                $user = AsabUser::withoutGlobalScopes()->find($branch->asab_manager_user_id);
                if ($user === null || $branch->asab_company_id === null) {
                    return;
                }

                $exists = Employee::withoutGlobalScopes()
                    ->where('company_id', $branch->asab_company_id)
                    ->where('branch_id', $branch->id)
                    ->where('name', $user->name)
                    ->exists();

                if ($exists) {
                    return;
                }

                $this->line(($dry ? '[dry] ' : '')."إضافة مدير الفرع للكشف: {$user->name} — {$branch->name}");
                $created++;

                if ($dry) {
                    return;
                }

                Employee::create([
                    'company_id' => $branch->asab_company_id,
                    'branch_id' => $branch->id,
                    'emp_number' => $this->nextEmpNumber($branch->asab_company_id),
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => 'مدير فرع',
                    'monthly_salary' => 0,
                    'hire_date' => $user->created_at ?? now(),
                    'status' => 'active',
                ]);
            });

        $this->info(($dry ? 'Would create ' : 'Created ')."{$created} manager employee record(s).");
    }
}
