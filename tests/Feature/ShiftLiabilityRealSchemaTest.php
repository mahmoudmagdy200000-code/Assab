<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Liability\DailyCloseEvidence;
use Modules\Shift\Liability\DailyLiabilityGuard;
use Modules\Shift\Liability\LiabilityEvidenceSource;
use Modules\Shift\Liability\ReportEvidence;
use Modules\Shift\Liability\ShiftLiabilityService;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Models\ShiftLiabilityShare;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * S1-07 internal liability layer against the real migrated schema and real models, resolved from the
 * container. The evidence source is a test double: real report/receipt evidence is S1-08/S1-10/S1-11
 * integration work. No HTTP route uses this layer yet.
 */
class ShiftLiabilityRealSchemaTest extends TestCase
{
    use RefreshDatabase;

    private RealSchemaTestEvidence $evidence;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Cashier $colleague;

    private CashierShift $shift;

    private BranchManagerShift $workday;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'S1-07 Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->colleague = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->shift = CashierShift::create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $this->workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create(['branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id, 'shift_date' => today()->toDateString()]);

        $this->evidence = new RealSchemaTestEvidence;
        $this->app->instance(LiabilityEvidenceSource::class, $this->evidence);
        $this->report(-2000, 'r1');
    }

    private function report(int $variance, string $revision): void
    {
        $companyId = $this->branch->asab_company_id;
        $this->evidence->report = new ReportEvidence($this->shift->id, $companyId, $this->branch->id, $revision, $variance, true);
        $this->evidence->scope = new DailyCloseEvidence($this->workday->id, $companyId, $this->branch->id, [$this->evidence->report], []);
    }

    private function service(): ShiftLiabilityService
    {
        return app(ShiftLiabilityService::class);
    }

    private function guard(): DailyLiabilityGuard
    {
        return app(DailyLiabilityGuard::class);
    }

    private function shares(): array
    {
        return [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1200],
            ['type' => 'branch_manager', 'id' => $this->manager->id, 'amount' => 800],
        ];
    }

    private function submitDay(): void
    {
        DB::transaction(fn () => $this->guard()->lockSubmittedDay($this->workday->id, $this->manager));
    }

    private function expectConflict(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected 409 {$code}");
        } catch (ConflictHttpException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    /** FIN-02: a shortage without a complete, confirmed allocation cannot pass the daily guard. */
    public function test_shortage_without_allocation_blocks_the_day_and_partial_allocation_is_refused(): void
    {
        $this->expectConflict('STALE_OR_MISSING_LIABILITY_ALLOCATION', fn () => $this->submitDay());

        try {
            $this->service()->allocate($this->shift->id, $this->cashier, [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1500]], 0, true);
            $this->fail('A 15 of 20 allocation must be refused');
        } catch (ValidationException) {
            $this->assertSame(0, ShiftLiabilityAllocation::count());
        }
        $this->assertSame(0, ShiftLiabilityDailyLock::count());
    }

    /** FIN-03 / FIN-10 / FIN-11 on the real schema: 12 + 8, cashier confirmation, explicit manager self-share. */
    public function test_full_cycle_with_real_models_then_day_lock_and_reopen(): void
    {
        $service = $this->service();
        $allocation = $service->allocate($this->shift->id, $this->cashier, $this->shares(), 0, false);
        $this->assertSame(2, $allocation->shares->count());
        $this->assertSame(2000, (int) $allocation->shares->sum('amount_halalas'));

        // The cashier's own name is a default, not an approval: unconfirmed allocation cannot be approved.
        $this->expectConflict('CASHIER_ALLOCATION_CONFIRMATION_REQUIRED', fn () => $service->approve($this->shift->id, $this->manager, 1, true));
        $service->confirm($this->shift->id, $this->cashier, 1);

        // FIN-11: the manager's own share needs an explicit self approval.
        $this->expectConflict('EXPLICIT_MANAGER_SELF_APPROVAL_REQUIRED', fn () => $service->approve($this->shift->id, $this->manager, 1));
        $service->respond($this->shift->id, $this->cashier, 1, 'objected', 'I was on break');
        $service->approve($this->shift->id, $this->manager, 1, true);

        $this->submitDay();
        $lock = ShiftLiabilityDailyLock::sole();
        $this->assertSame($this->shift->id, $lock->cashier_shift_id);
        $this->assertSame($this->workday->id, $lock->branch_manager_shift_id);
        $this->assertSame(1, $lock->allocation_version);
        $this->assertNull($lock->released_at);

        // After submit: no re-allocation, confirmation or approval …
        $this->expectConflict('LIABILITY_LOCKED_BY_DAILY_SUBMIT', fn () => $service->allocate($this->shift->id, $this->manager, $this->shares(), 1, false, 'late change'));
        $this->expectConflict('LIABILITY_LOCKED_BY_DAILY_SUBMIT', fn () => $service->approve($this->shift->id, $this->manager, 1, true));
        $this->expectConflict('LIABILITY_LOCKED_BY_DAILY_SUBMIT', fn () => $service->confirm($this->shift->id, $this->cashier, 1));
        $this->assertSame(1, ShiftLiabilityAllocation::count());
        $this->assertSame('approved', ShiftLiabilityAllocation::sole()->manager_approval_status);

        // … but an employee can still record a response (BR-10: an objection is evidence, never a block).
        $service->respond($this->shift->id, $this->manager, 1, 'accepted');
        $managerShare = ShiftLiabilityShare::where('responsible_type', 'branch_manager')->sole();
        $this->assertSame('accepted', $managerShare->employee_response_status);
        $this->assertNotNull($managerShare->employee_responded_at);
        $this->expectConflict('EMPLOYEE_RESPONSE_ALREADY_RECORDED', fn () => $service->respond($this->shift->id, $this->manager, 1, 'objected', 'changed my mind'));
        $this->assertSame('accepted', $managerShare->fresh()->employee_response_status);
        $this->assertSame('approved', ShiftLiabilityAllocation::sole()->manager_approval_status);

        // Reopen needs a reason, keeps the lock row, and unlocks correction.
        try {
            DB::transaction(fn () => $this->guard()->releaseDay($this->workday->id, $this->manager, ' '));
            $this->fail('Reopen without a reason must be refused');
        } catch (ValidationException) {
            $this->assertNull(ShiftLiabilityDailyLock::sole()->released_at);
        }
        $released = DB::transaction(fn () => $this->guard()->releaseDay($this->workday->id, $this->manager, 'Recount requested by accountant'));
        $this->assertSame(1, $released);
        $lock = ShiftLiabilityDailyLock::sole();
        $this->assertNotNull($lock->released_at);
        $this->assertSame('Recount requested by accountant', $lock->release_reason);
        $this->assertSame((string) $this->manager->id, $lock->released_by_id);

        $corrected = $service->allocate($this->shift->id, $this->manager, $this->shares(), 1, false, 'Manager correction after reopen');
        $this->assertSame(2, $corrected->version);
        $this->assertNotNull(ShiftLiabilityAllocation::where('version', 1)->value('superseded_at'));

        // Resubmitting the reopened day needs the new version approved, then locks it again.
        $this->expectConflict('LIABILITY_APPROVAL_REQUIRED', fn () => $this->submitDay());
        $service->approve($this->shift->id, $this->manager, 2, true);
        $this->submitDay();
        $this->assertSame(1, ShiftLiabilityDailyLock::active()->count());
        $this->assertSame(2, ShiftLiabilityDailyLock::count());
    }

    /** FIN-10: a share for a cashier of another branch is refused without writes. */
    public function test_out_of_branch_share_is_refused_without_writes(): void
    {
        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->branch->asab_company_id]);
        $outsider = Cashier::factory()->create(['branch_id' => $otherBranch->id, 'created_by' => $this->manager->id]);

        $this->expectException(AccessDeniedHttpException::class);
        try {
            $this->service()->allocate($this->shift->id, $this->cashier, [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1200],
                ['type' => 'cashier', 'id' => $outsider->id, 'amount' => 800],
            ], 0, true);
        } finally {
            $this->assertSame(0, ShiftLiabilityAllocation::count());
            $this->assertSame(0, ShiftLiabilityShare::count());
        }
    }

    /** Typed identity: an Admin employee of the same company and branch can carry a share. */
    public function test_admin_employee_share_resolves_on_the_real_schema(): void
    {
        $employee = Employee::create([
            'company_id' => $this->branch->asab_company_id, 'branch_id' => $this->branch->id,
            'emp_number' => '7001', 'name' => 'Kitchen', 'role' => 'cook', 'status' => 'active',
        ]);

        $allocation = $this->service()->allocate($this->shift->id, $this->cashier, [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1200],
            ['type' => 'employee', 'id' => $employee->id, 'amount' => 800],
        ], 0, true);

        $this->assertSame(['cashier', 'employee'], $allocation->shares->pluck('responsible_type')->sort()->values()->all());
    }

    /** FIN-04 / FIN-05: surplus and zero belong to the branch — no shares, no approval, day passes. */
    public function test_surplus_and_balanced_reports_need_no_employee_or_approval(): void
    {
        foreach ([500 => 'r-surplus', 0 => 'r-zero'] as $variance => $revision) {
            $this->report($variance, $revision);
            $this->expectConflict('NO_SHORTAGE_LIABILITY', fn () => $this->service()->allocate($this->shift->id, $this->cashier, [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500]], 0, true));
            $this->expectConflict('NO_SHORTAGE_LIABILITY', fn () => $this->service()->allocate($this->shift->id, $this->cashier, [], 0, true));
            $this->assertSame(0, ShiftLiabilityAllocation::count());
            $this->assertSame(0, ShiftLiabilityShare::count());
        }

        $this->submitDay();
        $this->assertSame(0, ShiftLiabilityAllocation::count());
        $this->assertSame(1, ShiftLiabilityDailyLock::count());
    }

    public function test_only_the_workday_manager_can_submit_or_reopen(): void
    {
        $otherManager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->report(500, 'r-surplus');

        foreach ([
            fn () => $this->guard()->lockSubmittedDay($this->workday->id, $otherManager),
            fn () => $this->guard()->releaseDay($this->workday->id, $otherManager, 'not mine'),
        ] as $action) {
            try {
                DB::transaction($action);
                $this->fail('Another manager must be refused');
            } catch (AccessDeniedHttpException $e) {
                $this->assertSame('ONLY_WORKDAY_MANAGER', $e->getMessage());
            }
        }
        $this->assertSame(0, ShiftLiabilityDailyLock::count());
    }

    /** Carry-over: a report included in two submitted days stays locked until both are reopened. */
    public function test_report_in_two_submitted_days_stays_locked_until_both_are_reopened(): void
    {
        $service = $this->service();
        $service->allocate($this->shift->id, $this->cashier, $this->shares(), 0, true);
        $service->approve($this->shift->id, $this->manager, 1, true);

        $nextDay = BranchManagerShift::create(['branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id, 'shift_date' => today()->addDay()->toDateString()]);
        $this->evidence->scopes[$nextDay->id] = new DailyCloseEvidence($nextDay->id, $this->branch->asab_company_id, $this->branch->id, [$this->evidence->report], []);

        $this->submitDay();
        DB::transaction(fn () => $this->guard()->lockSubmittedDay($nextDay->id, $this->manager));
        $this->assertSame(2, ShiftLiabilityDailyLock::active()->count());

        DB::transaction(fn () => $this->guard()->releaseDay($this->workday->id, $this->manager, 'Reopen first day'));
        $this->expectConflict('LIABILITY_LOCKED_BY_DAILY_SUBMIT', fn () => $service->allocate($this->shift->id, $this->manager, $this->shares(), 1, false, 'still locked by next day'));

        DB::transaction(fn () => $this->guard()->releaseDay($nextDay->id, $this->manager, 'Reopen second day'));
        $this->assertSame(2, $service->allocate($this->shift->id, $this->manager, $this->shares(), 1, false, 'both days reopened')->version);
        $this->assertSame(2, ShiftLiabilityDailyLock::count());
    }
}

final class RealSchemaTestEvidence implements LiabilityEvidenceSource
{
    public ReportEvidence $report;

    public DailyCloseEvidence $scope;

    /** @var array<string, DailyCloseEvidence> extra workdays (carry-over) */
    public array $scopes = [];

    public function report(string $cashierShiftId): ReportEvidence
    {
        return $this->report;
    }

    public function dailyClose(string $managerWorkdayId): DailyCloseEvidence
    {
        return $this->scopes[$managerWorkdayId] ?? $this->scope;
    }
}
