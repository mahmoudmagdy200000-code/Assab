<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\BranchHierarchyLinker;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\InvoiceDetail;
use Tests\TestCase;

/**
 * T05.11 — the two-worlds bridge. A mobile-app expense must surface in the
 * dashboard accountant's inbox with its per-invoice tax fields intact, and a
 * branch that belongs to no ASAB company must be left alone.
 */
class ExpenseBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function asabBranch(): array
    {
        $company = AsabCompany::create(['name' => 'Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['name' => 'فرع', 'asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);

        return [$company, $branch];
    }

    private function legacyExpense(Branch $branch, array $invoices): Expense
    {
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id,
            'expense_type' => 'grouped_invoice',
            'status' => 'draft',
            'total_amount' => array_sum(array_column($invoices, 'tax_total_amount')),
            'net_amount' => array_sum(array_column($invoices, 'tax_net_amount')),
            'vat_amount' => array_sum(array_column($invoices, 'tax_vat_amount')),
        ]);
        foreach ($invoices as $invoice) {
            InvoiceDetail::factory()->create(array_merge(['expense_id' => $expense->id], $invoice));
        }

        return $expense->fresh();
    }

    public function test_a_submitted_mobile_expense_lands_in_the_accountant_inbox(): void
    {
        [$company, $branch] = $this->asabBranch();
        $expense = $this->legacyExpense($branch, [
            ['invoice_number' => 'M-1', 'tax_supplier_name' => 'مورد الجملة', 'issue_date' => '2026-07-01',
                'tax_net_amount' => 100.00, 'tax_vat_amount' => 15.00, 'tax_total_amount' => 115.00],
            ['invoice_number' => 'M-2', 'tax_supplier_name' => 'مورد آخر', 'issue_date' => '2026-07-02',
                'tax_net_amount' => 200.00, 'tax_vat_amount' => 0.00, 'tax_total_amount' => 200.00],
        ]);

        event(new ExpenseSubmittedEvent($expense));

        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();
        $this->assertSame('expenses', $op->module_key);
        $this->assertSame('expense', $op->source_module);
        $this->assertSame('mobile', $op->origin);
        $this->assertSame(Operation::STATUS_PENDING, $op->status);
        $this->assertSame($company->id, $op->company_id);
        $this->assertSame(31500, $op->amount);

        $accountant = AsabUser::create([
            'company_id' => $company->id, 'name' => 'محاسب', 'email' => 'acc@bridge.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $invoices = $this->actingAs($accountant, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")->assertOk()->json('expenses.invoices');

        $this->assertCount(2, $invoices);
        $this->assertSame('M-1', $invoices[0]['invNum']);
        $this->assertSame(10000, $invoices[0]['preTaxHalalas']);
        $this->assertSame(1500, $invoices[0]['vat15Halalas']);
        // The zero-rated invoice keeps its own VAT line instead of being re-derived.
        $this->assertSame(0, $invoices[1]['vat15Halalas']);
        $this->assertSame(20000, $invoices[1]['preTaxHalalas']);
    }

    /**
     * Prod E2E 2026-07-31 (EXP-0011): a non-tax invoice carries tax_total_amount
     * 0.00 rather than null, so the null-coalescing fallback never fired and the
     * accountant's invoice table read «0.00 ر.س» — and matched ✅ — beneath a
     * header showing the real 3,008.00.
     */
    public function test_a_zero_tax_total_falls_back_to_the_statement_total(): void
    {
        [$company, $branch] = $this->asabBranch();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'draft',
            'total_amount' => 3008.00,
            'net_amount' => 2616.52,
            'vat_amount' => 392.48,
        ]);
        InvoiceDetail::factory()->create([
            'expense_id' => $expense->id, 'invoice_number' => 'hgfg555',
            'issue_date' => '2026-07-23',
            'tax_net_amount' => 0.00, 'tax_vat_amount' => 0.00, 'tax_total_amount' => 0.00,
        ]);

        event(new ExpenseSubmittedEvent($expense->fresh()));

        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();
        $this->assertSame(300800, $op->amount);
        $this->assertSame(300800, $op->payload['invoices'][0]['amountHalalas']);

        $accountant = AsabUser::create([
            'company_id' => $company->id, 'name' => 'محاسب', 'email' => 'acc@zero.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $body = $this->actingAs($accountant, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")->assertOk()->json();

        // The invoice row must foot to the header the accountant sees.
        $this->assertSame(300800, $body['expenses']['invoices'][0]['inclTaxHalalas']);
        $this->assertSame($body['amount'], $body['expenses']['invoices'][0]['inclTaxHalalas']);
    }

    public function test_the_linker_heals_brand_and_company_from_the_restaurant(): void
    {
        $company = AsabCompany::create(['name' => 'Heal Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $restaurant = AsabRestaurant::create(['company_id' => $company->id, 'brand_id' => $brand->id, 'name' => 'مطعم', 'status' => 'active']);
        // The bug shape: restaurant-linked but no brand tag.
        $branch = Branch::factory()->create(['asab_restaurant_id' => $restaurant->id, 'asab_brand_id' => null, 'asab_company_id' => null]);

        app(BranchHierarchyLinker::class)->ensure($branch);

        $this->assertSame($brand->id, $branch->fresh()->asab_brand_id);
        $this->assertSame($company->id, $branch->fresh()->asab_company_id);
    }

    public function test_a_brand_scoped_accountant_sees_a_mobile_expense_on_a_partially_tagged_branch(): void
    {
        $company = AsabCompany::create(['name' => 'Scope Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $restaurant = AsabRestaurant::create(['company_id' => $company->id, 'brand_id' => $brand->id, 'name' => 'مطعم', 'status' => 'active']);
        // Branch linked to the restaurant + company but NOT the brand — the shape
        // that made a mobile submission reach only the head, not the accountant.
        $branch = Branch::factory()->create([
            'name' => 'فرع', 'asab_restaurant_id' => $restaurant->id,
            'asab_company_id' => $company->id, 'asab_brand_id' => null,
        ]);

        $expense = $this->legacyExpense($branch, [
            ['invoice_number' => 'M-1', 'tax_supplier_name' => 'مورد', 'issue_date' => '2026-07-01',
                'tax_net_amount' => 100.00, 'tax_vat_amount' => 15.00, 'tax_total_amount' => 115.00],
        ]);

        event(new ExpenseSubmittedEvent($expense));

        // The bridge healed the branch's brand tag from its restaurant …
        $this->assertSame($brand->id, $branch->fresh()->asab_brand_id);

        // … so a BRAND-scoped accountant now resolves the branch and sees the op.
        $accountant = AsabUser::create([
            'company_id' => $company->id, 'name' => 'محاسب البراند', 'email' => 'acc@scope.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'brand', 'brand_ids' => [$brand->id],
        ]);

        $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/v1/accountant/operations?moduleKey=expenses')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_bridge_is_idempotent(): void
    {
        [, $branch] = $this->asabBranch();
        $expense = $this->legacyExpense($branch, [
            ['invoice_number' => 'M-1', 'tax_supplier_name' => 'مورد', 'issue_date' => '2026-07-01',
                'tax_net_amount' => 100.00, 'tax_vat_amount' => 15.00, 'tax_total_amount' => 115.00],
        ]);

        event(new ExpenseSubmittedEvent($expense));
        event(new ExpenseSubmittedEvent($expense));

        $this->assertSame(1, Operation::withoutGlobalScopes()->where('source_id', $expense->id)->count());
    }

    public function test_a_reviewed_operation_is_never_overwritten_by_the_mobile_record(): void
    {
        [, $branch] = $this->asabBranch();
        $expense = $this->legacyExpense($branch, [
            ['invoice_number' => 'M-1', 'tax_supplier_name' => 'مورد', 'issue_date' => '2026-07-01',
                'tax_net_amount' => 100.00, 'tax_vat_amount' => 15.00, 'tax_total_amount' => 115.00],
        ]);
        event(new ExpenseSubmittedEvent($expense));

        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();
        $op->update(['status' => Operation::STATUS_APPROVED, 'amount' => 999]);

        event(new ExpenseSubmittedEvent($expense->fresh()));

        $this->assertSame(999, (int) $op->fresh()->amount);
        $this->assertSame(Operation::STATUS_APPROVED, $op->fresh()->status);
    }

    public function test_the_payload_carries_supplier_identity_when_the_tax_name_is_absent(): void
    {
        [, $branch] = $this->asabBranch();
        $supplier = \Modules\Expense\Models\Supplier::factory()->create(['name' => 'مورد الكهرباء']);

        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $expense = Expense::factory()->create([
            'branch_manager_id' => $manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'pending',
            'supplier_id' => $supplier->id,
            'total_amount' => 115.00, 'net_amount' => 100.00, 'vat_amount' => 15.00,
        ]);
        // The meeting shape: a plain (non-tax) invoice — no tax_supplier_name
        // and no per-invoice supplier either, so the chain must reach the
        // expense-level supplier.
        InvoiceDetail::factory()->create([
            'expense_id' => $expense->id, 'invoice_number' => 'ELEC-1',
            'supplier_id' => null, 'tax_supplier_name' => null, 'issue_date' => '2026-07-29',
            'tax_net_amount' => null, 'tax_vat_amount' => null, 'tax_total_amount' => null,
        ]);

        event(new ExpenseSubmittedEvent($expense->fresh()));

        $op = Operation::withoutGlobalScopes()->where('source_id', $expense->id)->firstOrFail();
        $this->assertSame('مورد الكهرباء', $op->payload['supplierName']);
        $this->assertSame($expense->id, $op->payload['legacyExpenseId']);
        $this->assertSame('مورد الكهرباء', $op->payload['invoices'][0]['vendor']);
        $this->assertSame($supplier->id, $op->payload['invoices'][0]['supplierId']);
    }

    public function test_a_branch_outside_asab_produces_no_operation(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع قديم', 'asab_company_id' => null]);
        $expense = $this->legacyExpense($branch, [
            ['invoice_number' => 'M-1', 'tax_supplier_name' => 'مورد', 'issue_date' => '2026-07-01',
                'tax_net_amount' => 100.00, 'tax_vat_amount' => 15.00, 'tax_total_amount' => 115.00],
        ]);

        event(new ExpenseSubmittedEvent($expense));

        $this->assertSame(0, Operation::withoutGlobalScopes()->count());
    }
}
