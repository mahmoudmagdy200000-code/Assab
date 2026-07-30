<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseAttachment;
use Modules\Expense\Models\InvoiceDetail;
use Modules\Expense\Services\QuickCashExpenseService;
use Tests\TestCase;

/**
 * Meeting 2026-07-30 «الفاتورة اتبعتت ومجاتش»: an expense created straight as
 * pending (is_draft=false) never fired ExpenseSubmittedEvent, so the ASAB
 * bridge never ran and nothing was logged. Also: attachments never became
 * asab_attachments rows, and operation_date was the bridge-run clock.
 */
class ExpenseSubmissionBridgeTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;
        $brand = AsabBrand::create([
            'company_id' => $companyId, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $companyId,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_non_draft_create_bridges_to_an_operation_immediately(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        app(QuickCashExpenseService::class)->createQuickCashExpense([
            'expense_date' => now()->toDateString(),
            'expense_name' => 'فاتورة كهرباء',
            'total_amount' => 100.0,
            'payment_method' => 'cash',
            'is_draft' => false,
            'items' => [['title' => 'كهرباء', 'amount' => 100.0]],
        ]);

        $expense = Expense::firstOrFail();
        $this->assertNotNull($expense->submitted_at, 'non-draft create must stamp submitted_at');

        $op = Operation::withoutGlobalScopes()
            ->where('source_module', 'expense')->where('source_id', $expense->id)->first();
        $this->assertNotNull($op, 'a pending mobile expense must mint an asab operation without any extra submit call');
        $this->assertSame('expenses', $op->module_key);
        $this->assertSame($this->branch->id, $op->branch_id);
    }

    public function test_bridge_mirrors_attachments_and_keeps_the_real_date(): void
    {
        $expense = Expense::create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'pending',
            'submitted_at' => now()->subDays(7),
            'total_amount' => 250,
            'net_amount' => 217.39,
            'vat_amount' => 32.61,
            'payment_method' => 'cash',
        ]);
        $invoice = InvoiceDetail::create([
            'expense_id' => $expense->id,
            'invoice_number' => 'ELEC-23',
            'issue_date' => now()->subDays(7)->toDateString(),
            'is_tax_invoice' => false,
            'payment_type' => 'full',
            'paid_amount' => 250,
        ]);
        ExpenseAttachment::create([
            'expense_id' => $expense->id,
            'invoice_detail_id' => $invoice->id,
            'file_path' => 'expenses/receipts/expense_x_1.jpg',
            'file_name' => 'receipt.jpg',
            'file_type' => 'jpg',
            'file_size' => 1234,
        ]);
        ExpenseAttachment::create([
            'expense_id' => $expense->id,
            'file_path' => 'expenses/receipts/expense_x_2.pdf',
            'file_name' => 'statement.pdf',
            'file_type' => 'pdf',
            'file_size' => 5678,
        ]);

        $op = app(\Modules\Admin\Services\ExpenseBridgeService::class)->sync($expense);

        $this->assertNotNull($op);
        // The op lands on the submit day, not the bridge-run day.
        $this->assertSame(now()->subDays(7)->toDateString(), $op->operation_date->toDateString());

        $rows = Attachment::where('owner_id', $op->id)->get();
        $this->assertCount(2, $rows, 'mobile attachments must mirror into asab_attachments');
        $this->assertSame(2, $op->fresh()->attachment_count);

        $invoiceDoc = $rows->firstWhere('label', 'invoice:0');
        $this->assertNotNull($invoiceDoc);
        $this->assertSame('image/jpeg', $invoiceDoc->mime_type);
        // Storage::url() — '/storage/…' here (blank APP_URL), absolute in prod;
        // the point is it is a URL, not the bare storage key.
        $this->assertStringContainsString('/storage/', $invoiceDoc->public_url);
        $this->assertNotSame('expenses/receipts/expense_x_1.jpg', $invoiceDoc->public_url);

        $statementDoc = $rows->firstWhere('label', 'expense');
        $this->assertNotNull($statementDoc);
        $this->assertSame('application/pdf', $statementDoc->mime_type);

        // Re-sync must not duplicate.
        app(\Modules\Admin\Services\ExpenseBridgeService::class)->sync($expense->fresh());
        $this->assertSame(2, Attachment::where('owner_id', $op->id)->count());
    }
}
