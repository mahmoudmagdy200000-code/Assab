<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Expense;
use Tests\TestCase;

/**
 * Meeting 2026-07-29 «الفاتورة اتبعتت ومجاتش»: an expense submitted while its
 * branch was unlinked never bridged. After the admin links the branch,
 * asab:bridge-backfill must re-drive it into the accountant inbox.
 */
class BridgeBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private function strandedExpense(): array
    {
        // Submitted while the branch is OUTSIDE ASAB → the event-time bridge skipped it.
        $branch = Branch::factory()->create(['asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id,
            'expense_type' => 'quick_cash',
            'status' => 'pending',
            'total_amount' => 115.00, 'net_amount' => 100.00, 'vat_amount' => 15.00,
        ]);

        return [$branch, $expense];
    }

    public function test_it_bridges_a_stranded_expense_once_the_branch_is_linked(): void
    {
        [$branch, $expense] = $this->strandedExpense();
        $this->assertSame(0, Operation::withoutGlobalScopes()->count());

        // The admin links the branch (the meeting's fix), then ops runs the command.
        $company = AsabCompany::create(['name' => 'Backfill Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $branch->forceFill(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id])->save();

        $this->artisan('asab:bridge-backfill')->assertExitCode(0);

        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();
        $this->assertSame('expenses', $op->module_key);
        $this->assertSame('mobile', $op->origin);
        $this->assertSame($company->id, $op->company_id);
    }

    public function test_dry_run_writes_nothing_and_names_the_reason(): void
    {
        $this->strandedExpense();

        $this->artisan('asab:bridge-backfill --dry-run')->assertExitCode(0);

        $this->assertSame(0, Operation::withoutGlobalScopes()->count());
    }

    public function test_an_already_bridged_expense_is_not_duplicated(): void
    {
        $company = AsabCompany::create(['name' => 'Idem Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id, 'expense_type' => 'quick_cash', 'status' => 'pending',
            'total_amount' => 100, 'net_amount' => 100, 'vat_amount' => 0,
        ]);

        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense));
        $this->assertSame(1, Operation::withoutGlobalScopes()->where('source_id', $expense->id)->count());

        $this->artisan('asab:bridge-backfill')->assertExitCode(0);

        $this->assertSame(1, Operation::withoutGlobalScopes()->where('source_id', $expense->id)->count());
    }
}
