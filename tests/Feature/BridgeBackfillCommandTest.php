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

    /**
     * The default pass selects on the ABSENCE of a mirror, so a payload bug fixed
     * after an operation was minted never reaches production rows. --resync-payloads
     * re-maps mirrored-but-pending expenses in place (2026-07-31: the 0.00 invoice).
     */
    public function test_resync_payloads_refreshes_a_mirrored_pending_operation(): void
    {
        $company = AsabCompany::create(['name' => 'Resync Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id, 'expense_type' => 'single_invoice', 'status' => 'pending',
            'total_amount' => 3008.00, 'net_amount' => 2616.52, 'vat_amount' => 392.48,
        ]);
        \Modules\Expense\Models\InvoiceDetail::factory()->create([
            'expense_id' => $expense->id, 'invoice_number' => 'RS-1', 'issue_date' => '2026-07-23',
            'tax_net_amount' => 0.00, 'tax_vat_amount' => 0.00, 'tax_total_amount' => 0.00,
        ]);

        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->fresh()));
        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();

        // Simulate a row minted by the pre-fix code.
        $stale = $op->payload;
        $stale['invoices'][0]['amountHalalas'] = 0;
        $op->forceFill(['payload' => $stale])->save();

        $this->artisan('asab:bridge-backfill --resync-payloads')->assertExitCode(0);

        $this->assertSame(300800, $op->fresh()->payload['invoices'][0]['amountHalalas']);
        $this->assertSame(1, Operation::withoutGlobalScopes()->where('source_id', $expense->id)->count());
    }

    /**
     * Verification, document matching and asset conversion all write into the
     * payload of a STILL-PENDING operation, and sync() replaces the payload
     * wholesale — so pending-ness alone is not a safe filter.
     */
    public function test_resync_payloads_preserves_accountant_work_on_a_pending_operation(): void
    {
        $company = AsabCompany::create(['name' => 'Verified Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id, 'expense_type' => 'single_invoice', 'status' => 'pending',
            'total_amount' => 3008.00, 'net_amount' => 2616.52, 'vat_amount' => 392.48,
        ]);
        \Modules\Expense\Models\InvoiceDetail::factory()->create([
            'expense_id' => $expense->id, 'invoice_number' => 'VF-1', 'issue_date' => '2026-07-23',
            'tax_net_amount' => 0.00, 'tax_vat_amount' => 0.00, 'tax_total_amount' => 0.00,
        ]);

        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->fresh()));
        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();

        // The accountant verified the invoice and converted it to an asset draft,
        // while the operation is still pending.
        $payload = $op->payload;
        $payload['invoices'][0]['verified'] = true;
        $payload['invoices'][0]['verifiedBy'] = 'محاسب برجر';
        $payload['invoices'][0]['convertedToAsset'] = true;
        $payload['invoices'][0]['assetDraftId'] = 'draft-1';
        $op->forceFill(['payload' => $payload])->save();

        $this->artisan('asab:bridge-backfill --resync-payloads')->assertExitCode(0);

        $after = $op->fresh()->payload['invoices'][0];
        $this->assertTrue($after['verified']);
        $this->assertSame('محاسب برجر', $after['verifiedBy']);
        $this->assertTrue($after['convertedToAsset']);
        $this->assertSame('draft-1', $after['assetDraftId']);
    }

    /** A record the accountant already acted on must never be rewritten by the resync. */
    public function test_resync_payloads_leaves_a_reviewed_operation_alone(): void
    {
        $company = AsabCompany::create(['name' => 'Reviewed Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id, 'expense_type' => 'quick_cash', 'status' => 'pending',
            'total_amount' => 100, 'net_amount' => 100, 'vat_amount' => 0,
        ]);

        event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense));
        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();
        $op->forceFill(['status' => 'approved', 'payload' => ['invoices' => [], 'accountantEdited' => true]])->save();

        $this->artisan('asab:bridge-backfill --resync-payloads')->assertExitCode(0);

        $this->assertTrue($op->fresh()->payload['accountantEdited']);
    }
}
