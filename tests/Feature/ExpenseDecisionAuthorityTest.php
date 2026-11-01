<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Admin\Events\OperationApproved;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Events\OperationReturnedForReview;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Admin\Services\ExpenseFeedbackBridgeService;
use Modules\Admin\Services\OperationSequence;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Expense\Enums\ExpenseApprovalStage;
use Modules\Expense\Models\Expense;
use Modules\Expense\Services\ExpenseApprovalService;
use Tests\TestCase;

/**
 * BRC-03 / EXP-06 (TD-2) — an expense decision belongs to the brand owner / brand
 * manager in the mobile flow. The dashboard accountant and head of accounts may
 * LOOK at an `expenses` operation (read-only approval mirror) but every decision
 * transition is refused with 409 EXPENSE_DECISION_NOT_AUTHORIZED, before any state
 * change, event, notification or financial write. The reverse bridge
 * (dashboard decision -> legacy expense row, PRB-05) no longer writes.
 */
class ExpenseDecisionAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'EXPENSE_DECISION_NOT_AUTHORIZED';

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Authority Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'كرم الشام', 'abbr' => 'KS', 'status' => 'active']);
        $this->branch = Branch::factory()->create([
            'name' => 'فرع الأكاديميا', 'asab_company_id' => $this->company->id, 'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant = $this->dashUser('acc@auth.test', 'محاسب الفرع', 'accountant');
        $this->head = $this->dashUser('head@auth.test', 'رئيس الحسابات', 'head');
    }

    private function dashUser(string $email, string $name, string $role): AsabUser
    {
        $u = AsabUser::create([
            'company_id' => $this->company->id, 'name' => $name, 'email' => $email,
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $u->id,
            'role_key' => $role,
            'status' => 'active',
        ]);

        return $u;
    }

    private function ops(): OperationService
    {
        return app(OperationService::class);
    }

    private function brandOwner(): BrandOwner
    {
        return BrandOwner::create([
            'name' => 'مالك كرم الشام', 'email' => 'owner@auth.test', 'phone' => '0500000009',
            'password' => 'secret-password', 'is_active' => true, 'status' => 'active',
        ]);
    }

    /** A submitted mobile expense plus the (read-only mirror) operation it bridged into. */
    private function submitted(array $overrides = []): array
    {
        $expense = Expense::create(array_merge([
            'branch_manager_id' => $this->manager->id, 'expense_type' => 'quick_cash', 'status' => 'pending',
            'submitted_at' => now(), 'total_amount' => 400.00, 'net_amount' => 400.00, 'vat_amount' => 0.00,
            'payment_method' => 'supplier',
        ], $overrides));
        $op = app(ExpenseBridgeService::class)->sync($expense);
        $this->assertNotNull($op);

        return [$expense->fresh(), $op->fresh()];
    }

    /** An operation of another module (decision rights unchanged). */
    private function otherOp(string $module = 'sales', string $status = Operation::STATUS_PENDING, array $attrs = []): Operation
    {
        $attributes = array_merge([
            'public_id' => OperationSequence::next('OPS'), 'company_id' => $this->company->id,
            'branch_id' => $this->branch->id, 'module_key' => $module, 'amount' => 1000, 'match' => 'exact',
            'origin' => 'mobile', 'status' => $status, 'submitted_by_id' => $this->accountant->id,
            'submitted_at' => now(), 'operation_date' => now(),
        ], $attrs);
        if ($module !== 'sales') {
            return Operation::create($attributes);
        }
        $uploader = $this->dashUser(Str::uuid().'@test.invalid', 'Synthetic uploader', 'branch');
        $uploader->roleAssignments()->where('role_key', 'branch')->firstOrFail()->update(['scope' => 'branch', 'branch_ids' => [$this->branch->id]]);
        $previousActor = auth('sanctum')->user();
        $response = $this->actingAs($uploader, 'sanctum')->postJson('/api/v1/company/me/branch/upload',
            ['reportType' => 'sales', 'salesHalalas' => 1000])->assertCreated();
        $op = Operation::findOrFail($response->json('operations.0.id'));
        unset($attributes['company_id'], $attributes['submitted_by_id']);
        $op->forceFill($attributes)->save();
        $this->actingAs($previousActor ?? $this->head, 'sanctum');

        return $op->fresh();
    }

    /** Row counts that must not move on a refused decision. */
    private function footprint(Operation $op, Expense $expense): array
    {
        return [
            'steps' => ApprovalStep::where('operation_id', $op->id)->count(),
            'notifications' => AsabNotification::count(),
            'custody_transactions' => DB::table('custody_transactions')->count(),
            'personal_ledger_transactions' => DB::table('personal_ledger_transactions')->count(),
            'expense_timelines' => DB::table('expense_timelines')->where('expense_id', $expense->id)->count(),
            'op' => $this->scalars(Operation::find($op->id)->only(['status', 'approved_by_id', 'final_approved_by_id', 'rejected_by_id', 'reject_reason'])),
            'expense' => $this->scalars(Expense::find($expense->id)->only(['status', 'approval_stage', 'decided_by_name', 'decided_by_role', 'approved_at', 'rejected_at', 'rejection_reason'])),
        ];
    }

    /** Dates/enums as comparable scalars (assertSame compares objects by identity). */
    private function scalars(array $row): array
    {
        return array_map(fn ($v) => match (true) {
            $v instanceof \DateTimeInterface => $v->format('c'),
            $v instanceof \BackedEnum => $v->value,
            default => $v,
        }, $row);
    }

    private function assertNotAuthorized(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected '.self::CODE.' but the decision succeeded');
        } catch (AsabException $e) {
            $this->assertSame(self::CODE, $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }

    // ── Service level: every decision transition ─────────────────────────────

    public function test_every_decision_transition_on_an_expense_operation_is_refused_with_no_effect(): void
    {
        [$expense, $op] = $this->submitted();
        $before = $this->footprint($op, $expense);
        Event::fake([OperationApproved::class, OperationFinalApproved::class, OperationRejected::class, OperationReturnedForReview::class]);

        $this->assertNotAuthorized(fn () => $this->ops()->approve($op, $this->accountant));
        $this->assertNotAuthorized(fn () => $this->ops()->reject($op, $this->accountant, 'incomplete_data'));
        $this->assertNotAuthorized(fn () => $this->ops()->rejectAs($op, $this->accountant, 'incomplete_data'));
        $this->assertNotAuthorized(fn () => $this->ops()->rejectAs($op, $this->head, 'incomplete_data'));
        $this->assertNotAuthorized(fn () => $this->ops()->finalApprove($op, $this->head));
        $this->assertNotAuthorized(fn () => $this->ops()->returnForReview($op, $this->head));

        $this->assertSame($before, $this->footprint($op, $expense), 'zero writes: steps, notifications, custody, ledger, timeline, operation, expense');
        Event::assertNotDispatched(OperationApproved::class);
        Event::assertNotDispatched(OperationFinalApproved::class);
        Event::assertNotDispatched(OperationRejected::class);
        Event::assertNotDispatched(OperationReturnedForReview::class);
    }

    public function test_the_guard_applies_in_every_operation_status_including_the_accountant_stage(): void
    {
        [$expense, $op] = $this->submitted();

        // Historical in-flight shapes (an accountant-approved / returned mirror): still refused, not mutated.
        foreach ([Operation::STATUS_APPROVED, Operation::STATUS_PENDING] as $status) {
            DB::table('asab_operations')->where('id', $op->id)->update(['status' => $status]);
            $before = $this->footprint($op, $expense);

            $this->assertNotAuthorized(fn () => $this->ops()->finalApprove(Operation::find($op->id), $this->head));
            $this->assertNotAuthorized(fn () => $this->ops()->returnForReview(Operation::find($op->id), $this->head));
            $this->assertNotAuthorized(fn () => $this->ops()->approve(Operation::find($op->id), $this->accountant));

            $this->assertSame($before, $this->footprint($op, $expense));
        }
    }

    public function test_bulk_decisions_report_expense_operations_as_not_authorized_and_still_process_others(): void
    {
        [$expense, $op] = $this->submitted();
        $sales = $this->otherOp('sales');

        $r = $this->ops()->bulkApprove([$op->id, $sales->id], $this->accountant);
        $this->assertSame([$sales->public_id], $r['approved']);
        $this->assertSame([['id' => $op->id, 'code' => self::CODE]], $r['failed']);

        $approvedSales = $this->otherOp('sales', Operation::STATUS_APPROVED, ['approved_by_id' => $this->accountant->id]);
        $r = $this->ops()->bulkFinalApprove([$op->id, $approvedSales->id], $this->head);
        $this->assertSame([$approvedSales->public_id], $r['finalApproved']);
        $this->assertSame([['id' => $op->id, 'code' => self::CODE]], $r['failed']);

        $approvedSales2 = $this->otherOp('sales', Operation::STATUS_APPROVED, ['approved_by_id' => $this->accountant->id]);
        $r = $this->ops()->bulkReturnForReview([$op->id, $approvedSales2->id], $this->head);
        $this->assertSame([$approvedSales2->public_id], $r['returned']);
        $this->assertSame([['id' => $op->id, 'code' => self::CODE]], $r['failed']);

        $this->assertSame(Operation::STATUS_PENDING, Operation::find($op->id)->status);
        $this->assertSame('pending', Expense::find($expense->id)->status);
        $this->assertNull(Expense::find($expense->id)->approval_stage);
    }

    // ── HTTP: dashboard routes (all aliases reach OperationController) ───────

    public function test_dashboard_endpoints_return_409_with_the_stable_code_and_write_nothing(): void
    {
        [$expense, $op] = $this->submitted();
        $before = $this->footprint($op, $expense);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/approve")
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/reject", ['reason' => 'incomplete_data'])
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/reject", ['reason' => 'incomplete_data'])
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/final-approve")
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/operations/{$op->id}/return-for-review")
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);

        foreach ([
            ['bulk-approve', $this->accountant, []],
            ['bulk-final-approve', $this->head, []],
            ['bulk-return', $this->head, []],
        ] as [$path, $actor, $extra]) {
            $this->actingAs($actor, 'sanctum')
                ->postJson("/api/v1/operations/{$path}", array_merge(['operationIds' => [$op->id]], $extra))
                ->assertOk()
                ->assertJsonFragment(['code' => self::CODE]);
        }

        $this->assertSame($before, $this->footprint($op, $expense));
    }

    public function test_the_company_scoped_head_aliases_are_guarded_too(): void
    {
        [$expense, $op] = $this->submitted();
        $before = $this->footprint($op, $expense);

        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/operations/{$op->id}/final-approve")
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/operations/{$op->id}/return-for-review")
            ->assertStatus(409)->assertJsonPath('error.code', self::CODE);

        $this->assertSame($before, $this->footprint($op, $expense));
    }

    // ── PRB-05: "head approval = approved expense without any deduction" ─────

    public function test_prb05_head_final_approval_can_no_longer_approve_an_expense_without_a_deduction(): void
    {
        [$expense, $op] = $this->submitted(['payment_method' => 'custody']);
        $custodyBefore = DB::table('custody_transactions')->count();

        // The historical flow: accountant approves, head final-approves.
        $this->assertNotAuthorized(fn () => $this->ops()->approve($op, $this->accountant));
        $this->assertNotAuthorized(fn () => $this->ops()->finalApprove($op->fresh(), $this->head));

        $expense->refresh();
        $this->assertSame('pending', $expense->status, 'the legacy expense is NOT approved by the dashboard');
        $this->assertNull($expense->approval_stage);
        $this->assertSame($custodyBefore, DB::table('custody_transactions')->count());
    }

    // ── The brand owner path is untouched and the mirror follows it ──────────

    public function test_brand_owner_decisions_still_work_and_close_the_mirror(): void
    {
        $owner = $this->brandOwner();
        [$approvedExpense, $approvedOp] = $this->submitted();
        [$rejectedExpense, $rejectedOp] = $this->submitted();

        app(ExpenseApprovalService::class)->approveExpense($approvedExpense, $owner->id, $owner->name);
        app(ExpenseApprovalService::class)->rejectExpense($rejectedExpense, $owner->id, 'duplicate invoice');

        $this->assertSame('approved', $approvedExpense->fresh()->status);
        $this->assertSame(ExpenseApprovalStage::BRAND_OWNER_APPROVED, $approvedExpense->fresh()->approval_stage);
        $this->assertSame(Operation::STATUS_FINAL, $approvedOp->fresh()->status, 'mirror closed by the owner decision');
        $this->assertSame('rejected', $rejectedExpense->fresh()->status);
        $this->assertSame(Operation::STATUS_REJECTED, $rejectedOp->fresh()->status);
    }

    public function test_after_an_owner_decision_the_dashboard_still_gets_the_authority_error_not_a_state_error(): void
    {
        $owner = $this->brandOwner();
        [$expense, $op] = $this->submitted();
        app(ExpenseApprovalService::class)->approveExpense($expense, $owner->id, $owner->name);
        $before = $this->footprint($op, $expense);

        $this->assertNotAuthorized(fn () => $this->ops()->reject($op->fresh(), $this->head, 'incomplete_data'));
        $this->assertNotAuthorized(fn () => $this->ops()->finalApprove($op->fresh(), $this->head));
        $this->assertSame($before, $this->footprint($op, $expense));
    }

    public function test_the_expense_operation_stays_readable_on_the_dashboard(): void
    {
        [$expense, $op] = $this->submitted();

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk()
            ->assertJsonPath('moduleKey', 'expenses');
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}/audit-trail")
            ->assertOk();
    }

    // ── Reverse bridge (dashboard decision -> legacy expense) is retired ─────

    public function test_the_reverse_bridge_no_longer_writes_the_legacy_expense(): void
    {
        [$expense, $op] = $this->submitted();
        // Simulate a decision that reached the operation by any route other than the service.
        DB::table('asab_operations')->where('id', $op->id)->update([
            'status' => Operation::STATUS_FINAL, 'final_approved_by_id' => $this->head->id,
        ]);
        $op = Operation::find($op->id);

        event(new OperationFinalApproved($op, $this->head));
        app(ExpenseFeedbackBridgeService::class)->syncFromOperation($op, $this->head);

        $expense->refresh();
        $this->assertSame('pending', $expense->status);
        $this->assertNull($expense->approval_stage);
        $this->assertNull($expense->decided_by_name);
        $this->assertNull($expense->approved_at);
    }

    public function test_the_reverse_bridge_retirement_is_scoped_to_expenses(): void
    {
        // A non-expense decision still flows through the shared transitions untouched.
        $sales = $this->otherOp('sales');
        $this->ops()->approve($sales, $this->accountant);
        $this->ops()->finalApprove($sales->fresh(), $this->head);
        $this->assertSame(Operation::STATUS_FINAL, $sales->fresh()->status);

        $rejectable = $this->otherOp('sales');
        $this->ops()->reject($rejectable, $this->accountant, 'incomplete_data');
        $this->assertSame(Operation::STATUS_REJECTED, $rejectable->fresh()->status);

        $returnable = $this->otherOp('sales', Operation::STATUS_APPROVED, ['approved_by_id' => $this->accountant->id]);
        $this->ops()->returnForReview($returnable, $this->head);
        $this->assertSame(Operation::STATUS_PENDING, $returnable->fresh()->status);
    }

    // ── Statistic for historical expenses (read-only; never modified) ────────

    public function test_historical_stage_statistic_queries_count_without_modifying_data(): void
    {
        $this->seedStage('accountant_approved', 'pending', 100);
        $this->seedStage('accountant_approved', 'pending', 50);
        $this->seedStage('returned_to_accountant', 'pending', 70);
        $this->seedStage('head_approved', 'approved', 400);
        $this->seedStage('accountant_rejected', 'rejected', 30);
        $this->seedStage('brand_owner_approved', 'approved', 999); // not in scope
        $snapshot = DB::table('expenses')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $byStage = collect(DB::select(self::STAGE_SQL))->mapWithKeys(fn ($r) => [$r->approval_stage.'|'.$r->status => [(int) $r->expenses, (float) $r->total_amount]])->all();
        $this->assertSame([2, 150.0], $byStage['accountant_approved|pending']);
        $this->assertSame([1, 70.0], $byStage['returned_to_accountant|pending']);
        $this->assertSame([1, 400.0], $byStage['head_approved|approved']);
        $this->assertSame([1, 30.0], $byStage['accountant_rejected|rejected']);
        $this->assertArrayNotHasKey('brand_owner_approved|approved', $byStage);

        $inFlight = DB::select(self::IN_FLIGHT_SQL)[0];
        $this->assertSame(3, (int) $inFlight->expenses, 'accountant_approved x2 + returned_to_accountant: pending and now undecidable');
        $this->assertSame(220.0, (float) $inFlight->total_amount);

        $this->assertSame($snapshot, DB::table('expenses')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'statistic is read-only');
    }

    /** Same text as docs/sprint-01/pkg2-brc03-expense-decision-authority.md. */
    private const STAGE_SQL = "SELECT approval_stage, status, COUNT(*) AS expenses, SUM(total_amount) AS total_amount
FROM expenses
WHERE deleted_at IS NULL
  AND approval_stage IN ('accountant_approved','accountant_rejected','head_approved','head_rejected','returned_to_accountant')
GROUP BY approval_stage, status
ORDER BY approval_stage, status";

    private const IN_FLIGHT_SQL = "SELECT COUNT(*) AS expenses, COALESCE(SUM(total_amount), 0) AS total_amount
FROM expenses
WHERE deleted_at IS NULL
  AND status = 'pending'
  AND approval_stage IN ('accountant_approved','returned_to_accountant')";

    private function seedStage(string $stage, string $status, float $amount): void
    {
        $e = Expense::create([
            'branch_manager_id' => $this->manager->id, 'expense_type' => 'quick_cash', 'status' => $status,
            'submitted_at' => now(), 'total_amount' => $amount, 'net_amount' => $amount, 'vat_amount' => 0,
            'payment_method' => 'supplier',
        ]);
        DB::table('expenses')->where('id', $e->id)->update(['approval_stage' => $stage]);
    }
}
