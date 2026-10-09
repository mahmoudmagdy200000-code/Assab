<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Expense;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Notifications\BaseNotification;
use Modules\Shift\Events\DailyReportSubmittedEvent;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Transformers\CashierShiftResource;
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

    public function test_bridged_shift_carries_card_and_aggregators_so_expected_cash_excludes_non_cash(): void
    {
        // 1000 sales = 300 cash + 400 card + 300 across two delivery apps.
        // Expected cash must be the 300 cash portion, not the whole 1000 — else
        // the cashier is charged a fabricated 700 shortage (the bug this fixes).
        $legacy = CashierShift::factory()->create([
            'total_sales' => 1000.00, 'cash_collected' => 300.00,
            'card_payments' => 400.00, 'opening_balance' => 0.00,
        ]);
        $jahez = Aggregator::factory()->create(['name' => 'جاهز']);
        $keeta = Aggregator::factory()->create(['name' => 'كيتا']);
        ShiftSalesBreakdown::create(['cashier_shift_id' => $legacy->id, 'aggregator_id' => $jahez->id, 'amount' => 200.00]);
        ShiftSalesBreakdown::create(['cashier_shift_id' => $legacy->id, 'aggregator_id' => $keeta->id, 'amount' => 100.00]);
        $this->cashier->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();

        event(new ShiftEndedEvent($legacy, false));

        $shift = Shift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();

        $this->assertSame(40000, $op->payload['cardTotalHalalas']);
        $this->assertSame(30000, $op->payload['aggregatorTotalsHalalas']);
        $this->assertCount(2, $op->payload['aggregatorBreakdown']);
        // float(0) + max(0, 100000 − 40000 − 30000) = 30000; actual cash 30000 → zero variance.
        $this->assertSame(30000, $op->payload['cashExpectedHalalas']);
        $this->assertSame(0, $op->payload['varianceHalalas']);
        $this->assertSame(0, (int) $shift->fresh()->variance);
    }

    public function test_legacy_shift_bridge_converts_decimal_sar_to_halalas_exactly_once(): void
    {
        $legacy = CashierShift::factory()->create([
            'total_sales' => '123.45', 'cash_collected' => '40.01',
            'card_payments' => '50.02',
        ]);
        $app = Aggregator::factory()->create(['name' => 'جاهز']);
        ShiftSalesBreakdown::create(['cashier_shift_id' => $legacy->id, 'aggregator_id' => $app->id, 'amount' => '23.42']);
        $this->cashier->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();

        event(new ShiftEndedEvent($legacy, false));

        $shift = Shift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();

        $this->assertSame(12345, $op->payload['salesHalalas']);
        // The zero opening remains zero; Phase 1 does not synthesize receipt evidence.
        $this->assertSame(0, $op->payload['openingFloatHalalas']);
        $this->assertSame(5002, $op->payload['cardTotalHalalas']);
        $this->assertSame(2342, $op->payload['aggregatorTotalsHalalas']);
        $this->assertSame(4001, $op->payload['cashActualHalalas']);
        $this->assertSame('40.01', (string) $legacy->fresh()->cash_collected);
    }

    public function test_manager_daily_close_bridge_converts_decimal_sar_and_signed_variance_exactly(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $report = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'total_sales' => '123.45',
            'cash_collected' => '40.01',
            'card_payments' => '50.02',
            'aggregator_payments' => '23.42',
            'variance' => '-0.01',
        ]);

        event(new DailyReportSubmittedEvent($report));

        $op = Operation::where('module_key', 'sales')->where('payload->managerShiftId', $report->id)->firstOrFail();
        $this->assertSame(12345, $op->payload['totalHalalas']);
        $this->assertSame(4001, $op->payload['cashHalalas']);
        $this->assertSame(5002, $op->payload['cardHalalas']);
        $this->assertSame(2342, $op->payload['appsHalalas']);
        $this->assertSame(-1, $op->payload['varianceHalalas']);
        $this->assertSame('40.01', (string) $report->fresh()->cash_collected);
    }

    public function test_a_bridged_shift_with_an_opening_float_does_not_fabricate_a_shortage(): void
    {
        // Balanced shift (1000 = 300 cash + 400 card + 300 apps) carried a 100
        // float. cashActual is DRAWER cash = float + collected, so a balanced
        // shift nets ZERO variance — not a phantom −100 shortage charged to the
        // cashier (the mobile cash_collected excludes the float).
        $legacy = CashierShift::factory()->create([
            'total_sales' => 1000.00, 'cash_collected' => 300.00,
            'card_payments' => 400.00, 'opening_balance' => 100.00,
        ]);
        $jahez = Aggregator::factory()->create(['name' => 'جاهز']);
        ShiftSalesBreakdown::create(['cashier_shift_id' => $legacy->id, 'aggregator_id' => $jahez->id, 'amount' => 300.00]);
        $this->cashier->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();

        event(new ShiftEndedEvent($legacy, false));

        $shift = Shift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();

        // A declared opening without confirmed receipt is reset at creation;
        // only the 30000 collected cash is included in both values.
        $this->assertSame(30000, $op->payload['cashExpectedHalalas']);
        $this->assertSame(30000, $op->payload['cashActualHalalas']);
        $this->assertSame(0, $op->payload['varianceHalalas']);
        $this->assertSame(0, (int) $shift->fresh()->variance);
    }

    public function test_mobile_cashier_shift_resource_exposes_the_review_decision(): void
    {
        [$legacy, $op] = $this->bridgeLegacyShift();
        $this->ops()->reject($op, $this->head, 'incomplete_data');

        $arr = (new CashierShiftResource($legacy->refresh()->loadFullRelationships()))->toArray(request());

        $this->assertSame('rejected', $arr['review_status']);
        $this->assertSame('بيانات غير مكتملة', $arr['review_reason']);
        $this->assertNotNull($arr['reviewed_at']);
    }

    public function test_rejecting_a_bridged_shift_notifies_the_mobile_cashier(): void
    {
        Notification::fake();
        [$legacy, $op] = $this->bridgeLegacyShift();
        $cashier = $legacy->cashier;

        $this->ops()->reject($op, $this->head, 'incomplete_data');

        Notification::assertSentTo(
            $cashier,
            BaseNotification::class,
            fn (BaseNotification $n) => $n->type === NotificationType::SHIFT_SALES_REJECTED,
        );
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
