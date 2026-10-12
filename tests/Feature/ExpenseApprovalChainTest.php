<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Expense\Enums\ExpenseApprovalStage;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\QuickCashExpense;
use Modules\Expense\Models\Supplier;
use Modules\Expense\Services\ExpenseApprovalService;
use Tests\TestCase;

/**
 * Meeting 2026-08-14 — the two approval cycles of a mobile expense.
 *
 * Cycle 1 (brand owner decides in the app): terminal. The accountant and the
 * head of accounts see the outcome and can take no action.
 *
 * Cycle 2 (accountant decides on the dashboard):
 *   approve → still «pending» on the mobile side, stamped «موافق عليه من
 *             المحاسب», waiting on the head; head final-approves → «approved»
 *             carrying the HEAD's name; head rejects → back to «pending» for
 *             the accountant to review again.
 *   reject  → «rejected» back in the branch manager's Approval tab, stamped
 *             «مرفوض من المحاسب»; resubmitting reopens the operation.
 */
class ExpenseApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Chain Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'كرم الشام', 'abbr' => 'KS', 'status' => 'active']);
        $this->branch = Branch::factory()->create([
            'name' => 'فرع الأكاديميا',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب الفرع',
            'email' => 'acc@chain.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->head = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'رئيس الحسابات',
            'email' => 'head@chain.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
    }

    private function ops(): OperationService
    {
        return app(OperationService::class);
    }

    /** BrandOwner ships no factory — the mobile approver, minimally. */
    private function brandOwner(): BrandOwner
    {
        return BrandOwner::create([
            'name' => 'مالك كرم الشام',
            'email' => 'owner@chain.test',
            'phone' => '0500000001',
            'password' => 'secret-password',
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    private function approvals(): ExpenseApprovalService
    {
        return app(ExpenseApprovalService::class);
    }

    /** A submitted mobile expense plus the operation it bridged into. */
    private function submitted(array $overrides = []): array
    {
        $expense = Expense::create(array_merge([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'quick_cash',
            'status' => 'pending',
            'submitted_at' => now(),
            'total_amount' => 400.00,
            'net_amount' => 400.00,
            'vat_amount' => 0.00,
            'payment_method' => 'supplier',
        ], $overrides));

        $op = app(ExpenseBridgeService::class)->sync($expense);
        $this->assertNotNull($op, 'the expense must bridge into an operation');

        return [$expense->fresh(), $op->fresh()];
    }

    // ── Cycle 2 (accountant → head) — RETIRED by BRC-03 / EXP-06 (TD-2) ───────
    //
    // The dashboard accountant and head of accounts are not expense approvers. Each
    // former Cycle 2 test below now asserts the refusal (409 EXPENSE_DECISION_NOT_AUTHORIZED)
    // and that the mobile expense is left exactly as it was. Where a historical in-flight
    // shape is needed (an operation already `approved`, a record already rejected by the
    // accountant) it is seeded directly, as data that predates the retirement.

    /** The mobile expense must be exactly as submitted: nothing decided, nobody named. */
    private function assertExpenseUntouched(Expense $expense): void
    {
        $expense->refresh();
        $this->assertSame('pending', $expense->status);
        $this->assertNull($expense->approval_stage);
        $this->assertNull($expense->decided_by_name);
        $this->assertNull($expense->decided_by_role);
        $this->assertNull($expense->approved_at);
        $this->assertNull($expense->rejected_at);
        $this->assertNull($expense->rejection_reason);
        $this->assertFalse($expense->isDecisionLocked());
    }

    private function assertNotAuthorized(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected EXPENSE_DECISION_NOT_AUTHORIZED but the dashboard decision succeeded');
        } catch (\Modules\Admin\Exceptions\AsabException $e) {
            $this->assertSame('EXPENSE_DECISION_NOT_AUTHORIZED', $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_accountant_approval_is_refused_and_leaves_the_mobile_record_untouched(): void
    {
        [$expense, $op] = $this->submitted();

        $this->assertNotAuthorized(fn () => $this->ops()->approve($op, $this->accountant));

        $this->assertExpenseUntouched($expense);
        $this->assertSame(Operation::STATUS_PENDING, $op->fresh()->status);
        $this->assertNull($op->fresh()->approved_by_id);
    }

    public function test_head_final_approval_is_refused_and_does_not_approve_the_mobile_record(): void
    {
        [$expense, $op] = $this->submitted();
        // Historical shape: an operation the accountant had already approved.
        \Illuminate\Support\Facades\DB::table('asab_operations')->where('id', $op->id)->update(['status' => Operation::STATUS_APPROVED]);

        $this->assertNotAuthorized(fn () => $this->ops()->finalApprove($op->fresh(), $this->head));

        $this->assertExpenseUntouched($expense);
        $this->assertSame(Operation::STATUS_APPROVED, $op->fresh()->status);
        $this->assertNull($op->fresh()->final_approved_by_id);
    }

    public function test_accountant_rejection_is_refused_and_does_not_reject_the_mobile_record(): void
    {
        [$expense, $op] = $this->submitted();

        $this->assertNotAuthorized(fn () => $this->ops()->reject($op, $this->accountant, 'incomplete_data'));

        $this->assertExpenseUntouched($expense);
        $this->assertSame(Operation::STATUS_PENDING, $op->fresh()->status);
        $this->assertNull($op->fresh()->rejected_by_id);
    }

    public function test_head_rejecting_an_approved_record_is_refused_and_no_return_to_the_accountant_happens(): void
    {
        [$expense, $op] = $this->submitted();
        \Illuminate\Support\Facades\DB::table('asab_operations')->where('id', $op->id)->update([
            'status' => Operation::STATUS_APPROVED, 'approved_by_id' => $this->accountant->id,
        ]);

        $this->assertNotAuthorized(fn () => $this->ops()->rejectAs($op->fresh(), $this->head, 'incomplete_data'));

        $op->refresh();
        $this->assertSame(Operation::STATUS_APPROVED, $op->status, 'no return-for-review: the mirror is read-only');
        $this->assertSame($this->accountant->id, $op->approved_by_id);
        $this->assertExpenseUntouched($expense);
    }

    public function test_a_resubmitted_expense_reopens_its_rejected_operation(): void
    {
        [$expense, $op] = $this->submitted();
        // Historical shape: a record the accountant had rejected before the retirement
        // (expense rejected + stage, mirror rejected). Original assertions are unchanged.
        \Illuminate\Support\Facades\DB::table('expenses')->where('id', $expense->id)->update([
            'status' => 'rejected', 'approval_stage' => ExpenseApprovalStage::ACCOUNTANT_REJECTED->value,
            'decided_by_name' => 'محاسب الفرع', 'decided_by_role' => 'accountant',
            'rejected_at' => now(), 'rejection_reason' => 'بيانات غير مكتملة',
        ]);
        \Illuminate\Support\Facades\DB::table('asab_operations')->where('id', $op->id)->update([
            'status' => Operation::STATUS_REJECTED, 'rejected_by_id' => $this->accountant->id,
        ]);
        $this->assertSame(Operation::STATUS_REJECTED, $op->fresh()->status);

        // The resubmission is the manager's action — expense_timelines stamps
        // the actor and the column is NOT NULL.
        $this->actingAs($this->manager, 'sanctum');
        $this->approvals()->resubmitExpense($expense->fresh());

        $expense->refresh();
        $this->assertSame('pending', $expense->status);
        $this->assertNull($expense->approval_stage, 'a resubmission starts a fresh cycle');
        $this->assertSame(Operation::STATUS_PENDING, $op->fresh()->status, 'the accountant must see it again');
    }

    // ── Cycle 1: the brand owner decides in the app ──────────────────────────

    public function test_brand_owner_approval_closes_the_operation_for_the_dashboard(): void
    {
        [$expense, $op] = $this->submitted();
        $owner = $this->brandOwner();

        $this->approvals()->approveExpense($expense, $owner->id, $owner->name);

        $op->refresh();
        $this->assertSame(Operation::STATUS_FINAL, $op->status);
        $this->assertSame('مالك كرم الشام', $op->payload['legacyDecision']['byName']);
        $this->assertSame('brand_owner', $op->payload['legacyDecision']['byRole']);

        // Neither the accountant nor the head may act on it any more.
        $this->expectException(\Modules\Admin\Exceptions\AsabException::class);
        $this->ops()->approve($op, $this->accountant);
    }

    public function test_brand_owner_rejection_closes_the_operation_as_rejected(): void
    {
        [$expense, $op] = $this->submitted();
        $owner = $this->brandOwner();

        $this->approvals()->rejectExpense($expense, $owner->id, 'الفاتورة غير مقروءة', $owner->name);

        $op->refresh();
        $this->assertSame(Operation::STATUS_REJECTED, $op->status);
        $this->assertSame('الفاتورة غير مقروءة', $op->reject_reason);
        $this->assertSame(ExpenseApprovalStage::BRAND_OWNER_REJECTED, $expense->fresh()->approval_stage);
    }

    public function test_the_brand_owner_cannot_decide_a_record_the_accountant_already_took(): void
    {
        [$expense, $op] = $this->submitted();
        // Historical shape: a record the accountant had already taken before BRC-03 retired the
        // dashboard decision (the dashboard can no longer produce this stage itself).
        \Illuminate\Support\Facades\DB::table('expenses')->where('id', $expense->id)->update([
            'approval_stage' => ExpenseApprovalStage::ACCOUNTANT_APPROVED->value,
        ]);
        $owner = $this->brandOwner();

        $this->expectException(\Exception::class);
        $this->approvals()->approveExpense($expense->fresh(), $owner->id, $owner->name);
    }

    public function test_the_dashboard_never_overwrites_a_brand_owner_decision(): void
    {
        [$expense, $op] = $this->submitted();
        $owner = $this->brandOwner();
        $this->approvals()->approveExpense($expense, $owner->id, $owner->name);

        // A stale feedback event must not relabel the record as the head's.
        app(\Modules\Admin\Services\ExpenseFeedbackBridgeService::class)->syncFromOperation($op->fresh());

        $expense->refresh();
        $this->assertSame(ExpenseApprovalStage::BRAND_OWNER_APPROVED, $expense->approval_stage);
        $this->assertSame('مالك كرم الشام', $expense->decided_by_name);
    }

    // ── The quick-cash statement carries its supplier ────────────────────────

    public function test_a_quick_cash_expense_bridges_with_its_supplier_name(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'مؤسسة الخضار']);
        [$expense, $op] = $this->submitted(['supplier_id' => $supplier->id]);

        QuickCashExpense::create([
            'expense_id' => $expense->id,
            'expense_date' => now()->toDateString(),
            'expense_name' => 'شراء خضاروات',
            'has_vat' => false,
            'vat_total_amount' => 400.00,
            'invoice_number' => 'QC-77',
        ]);

        app(ExpenseBridgeService::class)->sync($expense->fresh());
        $op->refresh();

        // A quick-cash expense has no invoice_details at all; the statement row
        // is synthesised so «المورد» is not blank in the accountant's table.
        $invoice = $op->payload['invoices'][0] ?? null;
        $this->assertNotNull($invoice, 'a quick-cash expense must still bridge one invoice row');
        $this->assertSame('مؤسسة الخضار', $invoice['vendor']);
        $this->assertSame('QC-77', $invoice['invNum']);
        $this->assertSame('شراء خضاروات', $invoice['desc']);
        $this->assertSame(40000, $invoice['amountHalalas']);

        $presented = app(\Modules\Admin\Services\ExpenseInvoiceService::class)->present($op);
        $this->assertSame('مؤسسة الخضار', $presented['invoices'][0]['vendor']);
    }
}
