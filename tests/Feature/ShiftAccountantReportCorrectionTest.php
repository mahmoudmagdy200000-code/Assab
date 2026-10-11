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
        $this->assertSame('pending', $allocation->manager_approval_status);
        $this->assertSame('accountant', $allocation->created_by_type);
        $this->assertSame((string) $this->accountant->id, (string) $allocation->created_by_id);

        // Manager review required
        $review = ShiftReportManagerReview::where('report_revision_id', $rev2->id)->firstOrFail();
        $this->assertSame('pending', $review->status);
        $this->assertSame(-1000, $review->variance_halalas);
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
}
