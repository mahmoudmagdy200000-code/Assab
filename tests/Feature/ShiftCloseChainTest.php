<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\EmployeeAllocationService;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\ShiftCloseService;
use Modules\Branch\Models\Branch;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\CashierShift;
use Tests\TestCase;

/**
 * T08.6/8.7/8.12 — the shift close → accountant → head chain, the «خصم فرق كاش»
 * ledger post on final approval (auto-to-cashier + accountant override), and the
 * MOB-1.6 legacy cashier bridge.
 */
class ShiftCloseChainTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    private AsabUser $head;

    private Employee $cashier;

    private Employee $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Chain Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create(['company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@chain.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $this->head = AsabUser::create(['company_id' => $this->company->id, 'name' => 'رئيس', 'email' => 'head@chain.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);

        $this->cashier = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => '1001', 'name' => 'محمد', 'role' => 'كاشير', 'status' => 'active']);
        $this->supervisor = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => '1002', 'name' => 'خالد', 'role' => 'مشرف', 'status' => 'active']);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /** An open shift with a cashier, opening float and a running sales total. */
    private function openShift(int $sales = 50000, int $float = 0): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'supervisor_name' => 'خالد', 'cashier_employee_id' => $this->cashier->id, 'cashier_name' => 'محمد',
            'shift_type' => 'مسائي', 'started_at' => now(), 'status' => 'active',
            'sales_amount' => $sales, 'opening_float' => $float,
        ]);
    }

    public function test_admin_close_replays_across_aliases_with_one_operation(): void
    {
        $shift = $this->openShift();
        $key = (string) Str::uuid();
        $body = ['cashActualHalalas' => 47000];
        $first = $this->acc()->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/accountant/shifts/{$shift->id}/close", $body);
        $first->assertOk();
        $retry = $this->acc()->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/shifts/{$shift->id}/close", $body);
        $retry->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, Operation::where('module_key', 'shifts')->count());
        $this->assertSame('completed', DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    public function test_admin_close_snapshot_failure_rolls_back_and_same_key_can_retry(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite failure-injection trigger; MySQL concurrency is a separate gate.');
        }
        $shift = $this->openShift();
        $key = (string) Str::uuid();
        $body = ['cashActualHalalas' => 47000];
        DB::statement("CREATE TRIGGER block_admin_close_snapshot BEFORE UPDATE ON asab_command_idempotency_keys WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'snapshot unavailable'); END");
        try {
            $failed = $this->acc()->withHeader('Idempotency-Key', $key)
                ->postJson("/api/v1/company/me/shifts/{$shift->id}/close", $body);
            $failed->assertStatus(500);
            $this->assertSame('active', $shift->fresh()->status);
            $this->assertSame(0, Operation::where('module_key', 'shifts')->count());
            $this->assertSame(0, DB::table('asab_command_idempotency_keys')->count());
        } finally {
            DB::statement('DROP TRIGGER block_admin_close_snapshot');
        }
        $this->acc()->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/shifts/{$shift->id}/close", $body)->assertOk();
        $this->assertSame(1, Operation::where('module_key', 'shifts')->count());
        $this->assertSame('pending_review', $shift->fresh()->status);
    }

    private function ops(): OperationService
    {
        return app(OperationService::class);
    }

    private function shiftOp(Shift $shift): Operation
    {
        return Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();
    }

    // ── Close → pipeline (T08.6) ─────────────────────────────────────────────

    public function test_close_moves_the_shift_to_review_and_mints_a_shf_operation(): void
    {
        $shift = $this->openShift(sales: 50000);

        $body = $this->acc()->postJson("/api/v1/company/me/shifts/{$shift->id}/close", [
            'cashActualHalalas' => 47000,
            // A forged salesSystem must be ignored — expected is server-derived.
            'salesSystem' => 999999,
        ])->assertOk()->json();

        $this->assertSame('pending_review', $body['status']);
        $this->assertStringStartsWith('SHF-', $body['operationPublicId']);

        $shift->refresh();
        $this->assertSame('pending_review', $shift->status);
        $this->assertSame(50000, $shift->cash_expected);      // = sales, not the body
        $this->assertSame(-3000, $shift->variance);

        $op = $this->shiftOp($shift);
        $this->assertSame('pending', $op->status);
        $this->assertSame(-3000, $op->payload['varianceHalalas']);
    }

    public function test_reclosing_a_reviewed_shift_is_conflict(): void
    {
        $shift = $this->openShift();
        $this->acc()->postJson("/api/v1/company/me/shifts/{$shift->id}/close", ['cashActualHalalas' => 50000])->assertOk();

        $this->acc()->postJson("/api/v1/company/me/shifts/{$shift->id}/close", ['cashActualHalalas' => 50000])
            ->assertStatus(409)->assertJsonPath('error.code', 'SHIFT_ALREADY_CLOSED');
    }

    public function test_close_uses_locked_current_shift_values_for_expected_cash(): void
    {
        $shift = $this->openShift(sales: 50000, float: 0);
        $stale = $shift->fresh();
        Shift::query()->whereKey($shift->id)->update(['sales_amount' => 60000, 'opening_float' => 1000]);

        $closed = app(ShiftCloseService::class)->close($stale, ['cashActualHalalas' => 61000], $this->accountant, 'system');

        $this->assertSame(61000, $closed['shift']->cash_expected);
        $this->assertSame(0, $closed['shift']->variance);
    }

    public function test_close_rejects_a_stale_open_model_after_locked_state_changed(): void
    {
        $shift = $this->openShift();
        $stale = $shift->fresh();
        Shift::query()->whereKey($shift->id)->update(['status' => 'closed']);

        try {
            app(ShiftCloseService::class)->close($stale, ['cashActualHalalas' => 50000], $this->accountant, 'system');
            $this->fail('The current closed state must be re-read under lock.');
        } catch (\Modules\Admin\Exceptions\AsabException $exception) {
            $this->assertSame('SHIFT_ALREADY_CLOSED', $exception->errorCode);
        }

        $this->assertSame(0, Operation::where('module_key', 'shifts')->count());
    }

    // ── Ledger post on final approval (T08.7) ────────────────────────────────

    public function test_final_approval_charges_the_shortage_to_the_cashier(): void
    {
        $shift = $this->openShift(sales: 50000);
        app(ShiftCloseService::class)->close($shift, ['cashActualHalalas' => 47000], $this->accountant, 'system');
        $op = $this->shiftOp($shift);

        $this->ops()->approve($op, $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        $this->assertSame('closed', $shift->fresh()->status);
        $movements = EmployeeMovement::where('category', ShiftCloseService::CATEGORY)->get();
        $this->assertCount(1, $movements);
        $this->assertSame($this->cashier->id, $movements[0]->employee_id);
        $this->assertSame(3000, (int) $movements[0]->amount);
    }

    public function test_required_allocation_failure_rolls_back_final_approval_and_shift_close(): void
    {
        $shift = $this->openShift(sales: 50000);
        app(ShiftCloseService::class)->close($shift, ['cashActualHalalas' => 47000], $this->accountant, 'system');
        $op = $this->shiftOp($shift);
        $this->ops()->approve($op, $this->accountant);

        $allocationService = \Mockery::mock(EmployeeAllocationService::class);
        $allocationService->shouldReceive('post')->once()->andThrow(new \RuntimeException('injected allocation failure'));
        $this->app->instance(EmployeeAllocationService::class, $allocationService);

        try {
            $this->ops()->finalApprove($op->fresh(), $this->head);
            $this->fail('A required allocation failure must abort final approval.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected allocation failure', $exception->getMessage());
        }

        $this->assertSame(Operation::STATUS_APPROVED, $op->fresh()->status);
        $this->assertSame('pending_review', $shift->fresh()->status);
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $op->id)->count());
        $this->assertSame(0, $op->fresh()->steps()->where('stage_id', 'final')->count());
    }

    public function test_rejection_reopens_the_shift_and_posts_nothing(): void
    {
        $shift = $this->openShift(sales: 50000);
        app(ShiftCloseService::class)->close($shift, ['cashActualHalalas' => 47000], $this->accountant, 'system');
        $op = $this->shiftOp($shift);

        $this->ops()->reject($op, $this->head, 'incomplete_data');

        $this->assertSame('active', $shift->fresh()->status);
        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_a_balanced_shift_posts_no_charge(): void
    {
        $shift = $this->openShift(sales: 50000);
        app(ShiftCloseService::class)->close($shift, ['cashActualHalalas' => 50000], $this->accountant, 'system');
        $op = $this->shiftOp($shift);

        $this->ops()->approve($op, $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        $this->assertSame('closed', $shift->fresh()->status);
        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_accountant_override_splits_the_gap(): void
    {
        $shift = $this->openShift(sales: 50000);
        app(ShiftCloseService::class)->close($shift, ['cashActualHalalas' => 47000], $this->accountant, 'system');
        $op = $this->shiftOp($shift);

        // Wrong sum → 422.
        $this->acc()->postJson("/api/v1/company/me/shifts/{$shift->id}/variance-allocations", [
            'allocations' => [['employeeId' => $this->cashier->id, 'amountHalalas' => 1000]],
        ])->assertStatus(422);

        // Correct split across two employees.
        $this->acc()->postJson("/api/v1/company/me/shifts/{$shift->id}/variance-allocations", [
            'allocations' => [
                ['employeeId' => $this->cashier->id, 'amountHalalas' => 2000],
                ['employeeId' => $this->supervisor->id, 'amountHalalas' => 1000],
            ],
        ])->assertOk();

        $this->ops()->approve($op->fresh(), $this->accountant);
        $this->ops()->finalApprove($op->fresh(), $this->head);

        $movements = EmployeeMovement::where('category', ShiftCloseService::CATEGORY)->orderBy('amount')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(3000, (int) $movements->sum('amount'));
    }

    // ── Legacy cashier bridge (T08.12) ───────────────────────────────────────

    public function test_a_legacy_cashier_shift_close_creates_an_asab_operation(): void
    {
        // A real (persisted) legacy shift — the event serializes it for any
        // queued legacy listeners, so it must exist in the DB.
        $legacy = CashierShift::factory()->create(['total_sales' => 500.00, 'cash_collected' => 480.00, 'opening_balance' => 100.00]);
        $this->cashier->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();

        event(new ShiftEndedEvent($legacy, false));

        $shift = Shift::where('legacy_shift_id', $legacy->id)->first();
        $this->assertNotNull($shift);
        $this->assertSame($this->cashier->id, $shift->cashier_employee_id);
        $this->assertSame(50000, $shift->sales_amount);
        $this->assertSame(1, Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->count());

        // Idempotent — a repeat event does not double-bridge.
        event(new ShiftEndedEvent($legacy, false));
        $this->assertSame(1, Shift::where('legacy_shift_id', $legacy->id)->count());
    }
}
