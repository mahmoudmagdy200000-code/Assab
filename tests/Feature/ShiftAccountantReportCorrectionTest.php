<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftAccountantReportCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Shift $shiftTemplate;

    private Cashier $cashier;

    private BranchManager $manager;

    private Employee $employee;

    private AsabUser $accountant;

    private CashierShift $cashierShift;

    private ShiftReportRevisionService $revisionService;

    private ShiftCashCountService $countService;

    private AccountantReportCorrectionService $accountantCorrectionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'شركة الاختبار',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'علامة الاختبار',
            'abbr' => 'TEST',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'موظف تجريبي',
            'emp_number' => 'EMP-001',
            'role' => 'staff',
            'email' => 'emp1@test.com',
            'status' => 'active',
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'المحاسب',
            'email' => 'accountant@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        // Shift with total_sales = 100.00, card = 40.00, cash_collected = 60.00, count = 60.00 -> variance = 0
        $this->cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '40.00',
            'cash_collected' => '60.00',
            'closing_balance' => '60.00',
            'variance' => 0,
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
        $this->countService = app(ShiftCashCountService::class);
        $this->accountantCorrectionService = app(AccountantReportCorrectionService::class);
    }

    public function test_gross_sales_modification_is_strictly_forbidden_with_422(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ValidationException::class);

        $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['total_sales' => '120.00'],
            shares: null,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Try modifying sales',
            operationId: (string) Str::uuid()
        );
    }

    public function test_alternative_gross_sales_keys_are_strictly_forbidden(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ValidationException::class);

        $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['gross_sales' => '120.00'],
            shares: null,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Try gross_sales key',
            operationId: (string) Str::uuid()
        );
    }

    public function test_valid_card_correction_recalculates_variance_and_creates_pending_manager_review(): void
    {
        // 1. Initial count: 60.00 SAR physical cash, expected = 100.00 - 40.00 = 60.00 SAR -> variance = 0
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 6000,
            'expected_halalas' => 6000,
            'variance_halalas' => 0,
        ]);

        // 2. Accountant corrects card from 40.00 to 30.00 SAR:
        // expected cash becomes 100.00 - 30.00 = 70.00 SAR (7000h)
        // count remains 60.00 SAR (6000h)
        // variance becomes 60.00 - 70.00 = -10.00 SAR (-1000h shortage)
        $shares = [
            [
                'type' => 'cashier',
                'id' => $this->cashier->id,
                'amount' => 1000,
            ],
        ];

        $rev2 = $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '30.00'],
            shares: $shares,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Correcting card payments from pos statement',
            operationId: (string) Str::uuid()
        );

        $this->assertSame(2, $rev2->revision_number);

        // Verify count evidence preserved and variance updated
        $newCount = ShiftReportCashCount::where('report_revision_id', $rev2->id)->firstOrFail();
        $this->assertSame(6000, $newCount->counted_halalas);
        $this->assertSame(-1000, $newCount->variance_halalas);

        // Allocation created
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)
            ->whereNull('superseded_at')
            ->firstOrFail();
        $this->assertSame(-1000, $allocation->variance_halalas);
        $this->assertSame('approved', $allocation->manager_approval_status);
        $this->assertSame('accountant', $allocation->created_by_type);
        $this->assertSame((string) $this->accountant->id, (string) $allocation->created_by_id);

        // Manager review is NOT required/pending because accountant is final authority (COR-07 / RESOLVED-02)
        $review = ShiftReportManagerReview::where('report_revision_id', $rev2->id)->first();
        $this->assertNull($review);
    }

    public function test_distribution_only_correction_creates_new_revision_and_supersedes_prior_allocation(): void
    {
        // 1. Initial count with variance -10.00 SAR (-1000h)
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 5000,
            'expected_halalas' => 6000,
            'variance_halalas' => -1000,
        ]);

        // Allocate 100% to cashier
        $alloc1 = ShiftLiabilityAllocation::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -1000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashier->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'id' => (string) Str::uuid(),
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'amount_halalas' => 1000,
        ]);

        // 2. Distribution-only correction: reallocate to Cashier 600h + Employee 400h
        $newShares = [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 600],
            ['type' => 'employee', 'id' => $this->employee->id, 'amount' => 400],
        ];

        $rev2 = $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: [],
            shares: $newShares,
            expectedRevision: 1,
            expectedAllocationVersion: 1,
            reason: 'Splitting responsibility with employee',
            operationId: (string) Str::uuid()
        );

        $this->assertSame(2, $rev2->revision_number);

        // Old allocation superseded
        $this->assertNotNull($alloc1->fresh()->superseded_at);

        // New allocation active
        $alloc2 = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)
            ->whereNull('superseded_at')
            ->firstOrFail();
        $this->assertSame(2, $alloc2->version);
        $this->assertSame('2', $alloc2->report_revision);
        $this->assertSame(2, $alloc2->shares()->count());
        $this->assertSame($alloc1->id, $alloc2->supersedes_allocation_id);
    }

    public function test_compatible_shares_are_copied_to_a_new_pending_allocation_for_the_new_revision(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id, 'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id, 'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000, 'cards_halalas' => 4000, 'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0, 'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 5000, 'expected_halalas' => 6000, 'variance_halalas' => -1000,
        ]);
        $old = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id, 'version' => 1,
            'report_revision' => '1', 'variance_halalas' => -1000,
            'created_by_type' => 'accountant', 'created_by_id' => $this->accountant->id,
        ]);
        $old->shares()->create([
            'responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id,
            'amount_halalas' => 1000,
        ]);

        $revision = $this->accountantCorrectionService->correct(
            $this->cashierShift, $this->accountant,
            ['handover_notes' => 'verified note'], null, 1, 1,
            'Narrative correction with unchanged shortage', (string) Str::uuid()
        );

        $new = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)
            ->whereNull('superseded_at')->sole();
        $this->assertSame(2, $new->version);
        $this->assertSame((string) $revision->revision_number, $new->report_revision);
        $this->assertSame(-1000, $new->variance_halalas);
        $this->assertSame($old->id, $new->supersedes_allocation_id);
        $this->assertSame('approved', $new->manager_approval_status);
        $this->assertSame(1000, (int) $new->shares()->sum('amount_halalas'));
        $this->assertNotNull($old->fresh()->superseded_at);
    }

    public function test_negative_correction_fails_closed_without_a_complete_current_allocation(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id, 'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id, 'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000, 'cards_halalas' => 4000, 'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0, 'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 6000, 'expected_halalas' => 6000, 'variance_halalas' => 0,
        ]);

        try {
            $this->accountantCorrectionService->correct(
                $this->cashierShift, $this->accountant, ['card_payments' => '30.00'],
                null, 1, null, 'Create shortage without allocation', (string) Str::uuid()
            );
            $this->fail('A negative variance without a complete allocation must be blocked.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('LIABILITY_ALLOCATION_REQUIRED', $exception->getMessage());
        }

        $this->assertSame(1, $this->cashierShift->reportAggregate()->sole()->current_revision_number);
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)->count());
    }

    public function test_allocation_versions_remain_monotonic_after_zero_variance_removes_the_active_row(): void
    {
        $revision = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $revision->id, 'counted_revision_id' => $revision->id,
            'evidence_revision_id' => $revision->id, 'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000, 'cards_halalas' => 4000, 'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0, 'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 5000, 'expected_halalas' => 6000, 'variance_halalas' => -1000,
        ]);
        $v1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id, 'version' => 1,
            'report_revision' => '1', 'variance_halalas' => -1000,
            'created_by_type' => 'accountant', 'created_by_id' => $this->accountant->id,
        ]);
        $v1->shares()->create(['responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id, 'amount_halalas' => 1000]);

        $v2Revision = $this->accountantCorrectionService->correct(
            $this->cashierShift, $this->accountant, ['handover_notes' => 'version two'],
            [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000]],
            1, 1, 'Replace allocation at revision two', (string) Str::uuid()
        );
        $v2 = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)->whereNull('superseded_at')->sole();
        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->id, $v2->supersedes_allocation_id);

        $zeroRevision = $this->accountantCorrectionService->correct(
            $this->cashierShift, $this->accountant, ['card_payments' => '50.00'],
            null, $v2Revision->revision_number, 2, 'Correct report to zero variance', (string) Str::uuid()
        );
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)->whereNull('superseded_at')->count());

        $v3Revision = $this->accountantCorrectionService->correct(
            $this->cashierShift, $this->accountant, ['card_payments' => '40.00'],
            [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000]],
            $zeroRevision->revision_number, 2, 'Shortage returns after zero variance', (string) Str::uuid()
        );
        $v3 = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)->whereNull('superseded_at')->sole();
        $this->assertSame(3, $v3->version);
        $this->assertSame((string) $v3Revision->revision_number, $v3->report_revision);
        $this->assertSame($v2->id, $v3->supersedes_allocation_id);
        $this->assertNotNull($v2->fresh()->superseded_at);
    }

    public function test_stale_expected_revision_throws_409(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('STALE_REPORT_REVISION');

        $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '25.00'],
            shares: null,
            expectedRevision: 99,
            expectedAllocationVersion: null,
            reason: 'Stale revision',
            operationId: (string) Str::uuid()
        );
    }

    public function test_zero_shortage_accountant_correction_acknowledges_and_resolves_manager_review(): void
    {
        // 1. Initial count with shortage of -10.00 SAR (-1000h)
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 5000,
            'expected_halalas' => 6000,
            'variance_halalas' => -1000,
        ]);

        // 2. Accountant corrects card payments from 40.00 to 50.00 SAR:
        // expected cash becomes 100.00 - 50.00 = 50.00 SAR (5000h), matching count of 5000h -> variance = 0h.
        $rev2 = $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '50.00'],
            shares: null,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Card statement reconciled: zero shortage remaining',
            operationId: (string) Str::uuid()
        );

        $newCount = ShiftReportCashCount::where('report_revision_id', $rev2->id)->firstOrFail();
        $this->assertSame(0, $newCount->variance_halalas);

        // Manager review is NOT required for accountant correction per COR-07
        $review = ShiftReportManagerReview::where('report_revision_id', $rev2->id)->first();
        $this->assertNull($review);

        // Workday readiness succeeds immediately under accountant authority
        $managerDay = BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->manager->id, 'shift_date' => today()],
            ['branch_id' => $this->branch->id, 'status' => 'completed']
        );
        $managerDay->update(['status' => 'completed']);

        app(\Modules\Shift\Services\ShiftReportManagerReviewService::class)->assertReadyForWorkday($managerDay, [$this->cashierShift->id]);
    }

    public function test_accountant_final_authority_cannot_be_vetoed_or_reapproved_by_branch_manager(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 5000,
            'expected_halalas' => 6000,
            'variance_halalas' => -1000,
        ]);

        $shares = [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000],
        ];
        $rev2 = $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '40.00'],
            shares: $shares,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Still shortage',
            operationId: (string) Str::uuid()
        );

        $managerDay = BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->manager->id, 'shift_date' => today()],
            ['branch_id' => $this->branch->id, 'status' => 'completed']
        );
        $managerDay->update(['status' => 'completed']);

        // S111-FINAL-01: Manager review is NOT required and manager cannot veto
        app(\Modules\Shift\Services\ShiftReportManagerReviewService::class)->assertReadyForWorkday($managerDay, [$this->cashierShift->id]);

        // Attempting to review/approve accountant-final correction returns 409
        $response = $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/workday/shifts/{$this->cashierShift->id}/review-variance",
            [
                'expected_revision' => 2,
                'expected_allocation_version' => 1,
                'reason' => 'Manager attempts redundant approval',
            ],
            [
                'Idempotency-Key' => (string) Str::uuid(),
            ]
        );

        $response->assertStatus(409);
    }

    public function test_s111_final_01_accountant_final_authority_regressions_a_through_i(): void
    {
        // Setup employee A and employee B
        $employeeA = $this->employee;
        $employeeB = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'موظف ب',
            'emp_number' => 'EMP-002',
            'role' => 'staff',
            'email' => 'emp2@test.com',
            'status' => 'active',
        ]);

        // A. Branch manager approves initial shortage allocation (100% to employee A)
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 5000,
            'expected_halalas' => 6000,
            'variance_halalas' => -1000,
        ]);

        $alloc1 = ShiftLiabilityAllocation::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -1000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
            'reason' => 'Manager initial approved allocation',
        ]);
        $alloc1->shares()->create([
            'id' => (string) Str::uuid(),
            'responsible_type' => 'employee',
            'responsible_id' => $employeeA->id,
            'amount_halalas' => 1000,
        ]);

        // Post initial liability for A
        app(\Modules\Shift\Services\ShortagePostingService::class)->reconcile(
            $this->cashierShift,
            $this->manager,
            expectedRevision: 1,
            expectedAllocationVersion: 1,
            operationId: (string) Str::uuid()
        );

        $postingsAInitial = \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)
            ->where('responsible_id', $employeeA->id)->get();
        $this->assertCount(1, $postingsAInitial);
        $this->assertSame(1000, (int) $postingsAInitial->first()->delta_halalas);

        // B. Accountant later changes responsible employee A -> B
        $rev2 = $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: [],
            shares: [
                ['type' => 'employee', 'id' => (string) $employeeB->id, 'amount' => 1000],
            ],
            expectedRevision: 1,
            expectedAllocationVersion: 1,
            reason: 'Reassigning responsibility to employee B per audit',
            operationId: (string) Str::uuid()
        );

        // C. New revision becomes authoritative immediately under accountant authority
        $this->assertSame(2, $rev2->revision_number);
        $alloc2 = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)
            ->whereNull('superseded_at')
            ->firstOrFail();
        $this->assertSame(2, $alloc2->version);
        $this->assertSame('approved', $alloc2->manager_approval_status);
        $this->assertSame('accountant', $alloc2->created_by_type);

        // D. Manager cannot veto it by withholding a second approval
        $managerDay = BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->manager->id, 'shift_date' => today()],
            ['branch_id' => $this->branch->id, 'status' => 'completed']
        );
        $managerDay->update(['status' => 'completed']);
        // Workday readiness succeeds immediately:
        app(\Modules\Shift\Services\ShiftReportManagerReviewService::class)->assertReadyForWorkday($managerDay, [$this->cashierShift->id]);

        // Calling manager approval service fails with Conflict (accountant final authority):
        $managerBlocked = false;
        try {
            app(\Modules\Shift\Liability\ShiftLiabilityService::class)->approve($this->cashierShift->id, $this->manager, 2);
        } catch (ConflictHttpException $e) {
            $managerBlocked = true;
            $this->assertSame('ACCOUNTANT_FINAL_AUTHORITY', $e->getMessage());
        }
        $this->assertTrue($managerBlocked);

        // E. Old manager approval remains historical
        $this->assertNotNull($alloc1->fresh()->superseded_at);
        $this->assertSame('approved', $alloc1->fresh()->manager_approval_status);
        $this->assertSame((string) $this->manager->id, (string) $alloc1->fresh()->manager_approved_by);

        // F. A liability is reversed and B liability is posted by delta only
        $allPostingsA = \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)
            ->where('responsible_id', $employeeA->id)->get();
        $this->assertCount(2, $allPostingsA);
        $this->assertSame(1000, (int) $allPostingsA[0]->delta_halalas);
        $this->assertSame(-1000, (int) $allPostingsA[1]->delta_halalas);

        $allPostingsB = \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)
            ->where('responsible_id', $employeeB->id)->get();
        $this->assertCount(1, $allPostingsB);
        $this->assertSame(1000, (int) $allPostingsB[0]->delta_halalas);

        // G. Retry creates no duplicate posting
        $retryOpId = (string) Str::uuid();
        $retryResult = app(\Modules\Shift\Services\ShortagePostingService::class)->reconcile(
            $this->cashierShift,
            $this->accountant,
            expectedRevision: 2,
            expectedAllocationVersion: 2,
            operationId: $retryOpId
        );
        $this->assertSame(0, $retryResult['total_deltas']);
        $this->assertCount(2, \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)
            ->where('responsible_id', $employeeA->id)->get());
        $this->assertCount(1, \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)
            ->where('responsible_id', $employeeB->id)->get());

        // H. Employee objection does not block accountant-final state
        $shareB = $alloc2->shares()->where('responsible_id', $employeeB->id)->firstOrFail();
        app(\Modules\Shift\Liability\ShiftLiabilityService::class)->respond(
            $this->cashierShift->id,
            $employeeB,
            2,
            'objected',
            'I object to this allocation'
        );
        $this->assertSame('objected', $shareB->fresh()->employee_response_status);
        // Workday readiness still succeeds:
        app(\Modules\Shift\Services\ShiftReportManagerReviewService::class)->assertReadyForWorkday($managerDay, [$this->cashierShift->id]);

        // I. Accountant still cannot edit total_sales
        $this->expectException(ValidationException::class);
        $this->accountantCorrectionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['total_sales' => '200.00'],
            shares: null,
            expectedRevision: 2,
            expectedAllocationVersion: 2,
            reason: 'Attempting to change total sales',
            operationId: (string) Str::uuid()
        );
    }
}
