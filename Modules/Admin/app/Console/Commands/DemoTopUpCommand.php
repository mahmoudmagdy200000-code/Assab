<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationFactory;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;

/**
 * Prod E2E 2026-07-31: the live demo database predates the seeder fixes, so the
 * accountant's المبيعات and الهدر tabs open empty and the branch-portal login does
 * not exist — and a production database holding real records can never be
 * re-seeded to get them.
 *
 * This tops the demo up ADDITIVELY: every step is skipped when the data already
 * exists, nothing is overwritten, and no real record is touched. It is the
 * re-runnable counterpart to FullDemoSeeder for an already-live database.
 */
class DemoTopUpCommand extends Command
{
    protected $signature = 'asab:demo-topup
        {--company= : Restrict to one ASAB company id (default: every company that has branches)}
        {--dry-run : Report what is missing without writing anything}';

    protected $description = 'Additively fill demo gaps (branch-portal login, sales operation, waste operation) on a live database';

    public function handle(OperationFactory $operations): int
    {
        $dry = (bool) $this->option('dry-run');

        $branches = Branch::query()
            ->whereNotNull('asab_company_id')
            ->when($this->option('company'), fn ($q, $c) => $q->where('asab_company_id', $c))
            ->orderBy('created_at')
            ->get();

        if ($branches->isEmpty()) {
            $this->warn('No ASAB-linked branches found — nothing to top up.');

            return self::SUCCESS;
        }

        $this->branchPortalUser($branches->first(), $dry);
        $this->salesOperation($branches, $dry);
        $this->wasteOperation($operations, $branches, $dry);

        if ($dry) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * «بوابة الفرع» — the branch-upload write path sits behind asab.role:branch
     * with no head/admin bypass, so without this login §6 is un-demonstrable.
     * A password is minted ONLY when the account is absent: an existing login
     * keeps its own credential rather than being silently reset.
     */
    private function branchPortalUser(Branch $branch, bool $dry): void
    {
        $existing = AsabUser::withoutGlobalScopes()->where('email', 'branch@nakhat.sa')->first();

        if ($existing) {
            $hasRole = AsabUserRole::where('user_id', $existing->id)->where('role_key', 'branch')->exists();
            if ($hasRole) {
                $this->line('  • branch portal login: already present — untouched.');

                return;
            }

            if (! $dry) {
                AsabUserRole::updateOrCreate(
                    ['user_id' => $existing->id, 'role_key' => 'branch'],
                    [
                        'scope' => 'branch',
                        'brand_ids' => [],
                        'restaurant_ids' => [],
                        'branch_ids' => [$branch->id],
                        'module_keys' => ['sales', 'expenses', 'purchases', 'inventory', 'shifts', 'assets'],
                    ],
                );
            }
            $this->info('  • branch portal login: existed without the branch role — role granted.');

            return;
        }

        if ($dry) {
            $this->info('  • branch portal login: MISSING — would be created on '.$branch->name.'.');

            return;
        }

        $user = AsabUser::create([
            'company_id' => $branch->asab_company_id,
            'name' => 'بوابة '.$branch->name,
            'avatar' => 'ب',
            'email' => 'branch@nakhat.sa',
            'password' => 'password',   // hashed by the model's 'hashed' cast — demo credential, same as the seeder
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'branch',
            'scope' => 'branch',
            'brand_ids' => [],
            'restaurant_ids' => [],
            'branch_ids' => [$branch->id],
            'module_keys' => ['sales', 'expenses', 'purchases', 'inventory', 'shifts', 'assets'],
        ]);

        $this->info('  • branch portal login: created on '.$branch->name.'.');
    }

    /**
     * A completed manager daily close fires DailyReportSubmittedEvent, which the
     * bridge turns into the module_key=sales operation the المبيعات screen lists.
     * Branches whose manager already has a row for today are SKIPPED — forcing a
     * live shift to completed would corrupt a real working day.
     *
     * @param  \Illuminate\Support\Collection<int, Branch>  $branches
     */
    private function salesOperation($branches, bool $dry): void
    {
        if (Operation::withoutGlobalScopes()->where('module_key', 'sales')->exists()) {
            $this->line('  • sales operation: already present — untouched.');

            return;
        }

        foreach ($branches as $branch) {
            $manager = BranchManager::where('branch_id', $branch->id)->orderBy('created_at')->first();
            if ($manager === null) {
                continue;
            }

            if (BranchManagerShift::where('branch_manager_id', $manager->id)->whereDate('shift_date', today())->exists()) {
                continue;   // a live day — never rewrite it.
            }

            $totals = CashierShift::query()
                ->whereIn('cashier_id', Cashier::where('branch_id', $branch->id)->pluck('id'))
                ->where('status', ShiftStatus::COMPLETED)
                ->selectRaw('COALESCE(SUM(total_sales),0) s, COALESCE(SUM(cash_collected),0) c, COALESCE(SUM(card_payments),0) k')
                ->first();

            if ((float) $totals->s <= 0) {
                continue;   // nothing sold — a zero-value sales card demos worse than none.
            }

            if ($dry) {
                $this->info('  • sales operation: MISSING — would close '.$branch->name.' at '.$totals->s.' SAR.');

                return;
            }

            $shift = DB::transaction(fn () => BranchManagerShift::create([
                'branch_manager_id' => $manager->id,
                'shift_date' => today(),
                'branch_id' => $branch->id,
                'status' => 'completed',
                'actual_start_time' => now()->subHours(9),
                'actual_end_time' => now(),
                'total_sales' => $totals->s,
                'net_sales' => $totals->s,
                'cash_collected' => $totals->c,
                'card_payments' => $totals->k,
                'aggregator_payments' => $totals->s - $totals->c - $totals->k,
                'variance' => 0,
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
            ]));

            event(new \Modules\Shift\Events\DailyReportSubmittedEvent($shift));
            $this->info('  • sales operation: minted from '.$branch->name.' ('.$totals->s.' SAR).');

            return;
        }

        $this->warn('  • sales operation: MISSING and could not be minted — no branch has completed cashier shifts on a free day.');
    }

    /**
     * The الهدر screen lists module_key=waste operations, which normally arrive
     * from the branch portal upload. Mint one through the same factory the portal
     * uses so the record is indistinguishable from a real submission.
     *
     * @param  \Illuminate\Support\Collection<int, Branch>  $branches
     */
    private function wasteOperation(OperationFactory $operations, $branches, bool $dry): void
    {
        if (Operation::withoutGlobalScopes()->where('module_key', 'waste')->exists()) {
            $this->line('  • waste operation: already present — untouched.');

            return;
        }

        $branch = $branches->first();
        $submitter = AsabUser::withoutGlobalScopes()
            ->where('company_id', $branch->asab_company_id)
            ->whereHas('roleAssignments', fn ($q) => $q->where('role_key', 'branch'))
            ->first();

        if ($submitter === null) {
            $this->warn('  • waste operation: MISSING — needs the branch-portal login (run this command again after it is created).');

            return;
        }

        $products = [
            ['name' => 'دجاج مقطّع', 'qty' => 3.5, 'value' => 8750, 'classification' => 'تلف', 'responsibility' => 'الفرع'],
            ['name' => 'خبز برجر', 'qty' => 20, 'value' => 3000, 'classification' => 'انتهاء صلاحية', 'responsibility' => 'الفرع'],
        ];
        $amount = (int) array_sum(array_column($products, 'value'));

        if ($dry) {
            $this->info('  • waste operation: MISSING — would mint one on '.$branch->name.' ('.($amount / 100).' SAR).');

            return;
        }

        $op = $operations->createFromUpload(
            'waste',
            ['products' => $products, 'reportDate' => today()->toDateString()],
            $submitter,
            $branch->id,
            $amount,
            'mobile',
            'mobile_app',
        );

        $this->info('  • waste operation: minted '.$op->public_id.' on '.$branch->name.'.');
    }
}
