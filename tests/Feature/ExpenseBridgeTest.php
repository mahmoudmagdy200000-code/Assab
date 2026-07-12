<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
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
