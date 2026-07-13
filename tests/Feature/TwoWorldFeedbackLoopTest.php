<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\ExpenseFeedbackBridgeService;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\ShiftCloseService;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\CashierShift;
use Tests\TestCase;

/**
 * Two-worlds reverse sync (WS1a/WS1b): the dashboard's terminal review decision
 * flows BACK to the legacy mobile row so the phone app stops showing «معلق».
 *
 *  - WS1a shift: final-approve/reject writes cashier_shifts.review_status
 *    WITHOUT touching the frozen `status` (informational only, per product).
 *  - WS1b expense: final-approve/reject writes expenses.status, leaving the
 *    users-FK actor columns NULL.
 */
class TwoWorldFeedbackLoopTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    private AsabUser $head;

    private Employee $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Loop Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create(['company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@loop.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $this->head = AsabUser::create(['company_id' => $this->company->id, 'name' => 'رئيس', 'email' => 'head@loop.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);

        $this->cashier = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => '2001', 'name' => 'محمد', 'role' => 'كاشير', 'status' => 'active']);
    }

    private function ops(): OperationService
    {
        return app(OperationService::class);
    }

    /** Bridge a legacy mobile shift into the pipeline and return its SHF op. */
    private function bridgeLegacyShift(): array
    {
        $legacy = CashierShift::factory()->create(['total_sales' => 500.00, 'cash_collected' => 470.00, 'opening_balance' => 0.00]);
        $this->cashier->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();

        event(new ShiftEndedEvent($legacy, false));

        $shift = Shift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();

        return [$legacy, $op];
    }

    // ── WS1a: shift decision → legacy cashier_shifts.review_status ────────────

    public function test_final_approved_shift_marks_legacy_review_approved_without_touching_status(): void
    {
        [$legacy, $op] = $this->bridgeLegacyShift();
        $originalStatus = $legacy->status->value;

        $this->ops()->approve($op, $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        $legacy->refresh();
        $this->assertSame('approved', $legacy->review_status);
        $this->assertNotNull($legacy->reviewed_at);
        // Frozen status is never mutated — the mobile till stays closed.
        $this->assertSame($originalStatus, $legacy->status->value);
    }

    public function test_rejected_shift_marks_legacy_review_rejected_with_reason(): void
    {
        [$legacy, $op] = $this->bridgeLegacyShift();

        $this->ops()->reject($op, $this->head, 'incomplete_data');

        $legacy->refresh();
        $this->assertSame('rejected', $legacy->review_status);
        $this->assertSame('بيانات غير مكتملة', $legacy->review_reason);
    }

    public function test_dashboard_native_shift_without_legacy_id_syncs_nothing_and_does_not_error(): void
    {
        $shift = Shift::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'supervisor_name' => 'خالد', 'cashier_employee_id' => $this->cashier->id, 'cashier_name' => 'محمد',
            'shift_type' => 'مسائي', 'started_at' => now(), 'status' => 'active',
            'sales_amount' => 50000, 'opening_float' => 0,
        ]);
        app(ShiftCloseService::class)->close($shift, ['cashActualHalalas' => 50000], $this->accountant, 'system');
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();

        $this->ops()->approve($op, $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        $this->assertSame('closed', $shift->fresh()->status);
        $this->assertSame(0, CashierShift::count()); // no legacy row was created/touched
    }

    // ── WS1b: expense decision → legacy expenses.status ──────────────────────

    /** A legacy expense mirrored into a pending expenses operation. */
    private function bridgeLegacyExpense(string $publicId): array
    {
        $expense = Expense::factory()->pending()->create();
        $op = Operation::create([
            'public_id' => $publicId,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'expenses',
            'source_module' => 'expense',
            'source_id' => $expense->id,
            'payload' => [],
            'amount' => 11500,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'submitted_at' => now(),
            'operation_date' => now(),
        ]);

        return [$expense, $op];
    }

    public function test_final_approved_expense_marks_legacy_expense_approved_leaving_actor_null(): void
    {
        [$expense, $op] = $this->bridgeLegacyExpense('EXP-FB-1');

        $this->ops()->approve($op, $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        $expense->refresh();
        $this->assertSame('approved', $expense->status);
        $this->assertNotNull($expense->approved_at);
        // approved_by FKs to the legacy users table — an AsabUser id must NOT land here.
        $this->assertNull($expense->approved_by);
    }

    public function test_rejected_expense_marks_legacy_expense_rejected_with_reason(): void
    {
        [$expense, $op] = $this->bridgeLegacyExpense('EXP-FB-2');

        $this->ops()->reject($op, $this->head, 'incomplete_data');

        $expense->refresh();
        $this->assertSame('rejected', $expense->status);
        $this->assertSame('بيانات غير مكتملة', $expense->rejection_reason);
        $this->assertNull($expense->rejected_by);
    }

    public function test_expense_write_back_is_idempotent_and_ignores_non_expense_ops(): void
    {
        [$expense, $op] = $this->bridgeLegacyExpense('EXP-FB-3');
        $this->ops()->approve($op, $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        // Re-running the bridge on the same op changes nothing and does not throw.
        app(ExpenseFeedbackBridgeService::class)->syncFromOperation($op->fresh());
        $this->assertSame('approved', $expense->fresh()->status);

        // A non-expense operation is a no-op.
        $shiftOp = Operation::create([
            'public_id' => 'SHF-FB-9', 'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'module_key' => 'shifts', 'source_module' => 'shift', 'source_id' => $expense->id,
            'payload' => [], 'amount' => 0, 'origin' => 'mobile', 'status' => Operation::STATUS_FINAL,
            'operation_date' => now(),
        ]);
        app(ExpenseFeedbackBridgeService::class)->syncFromOperation($shiftOp);
        $this->assertSame('approved', $expense->fresh()->status); // untouched
    }
}
