<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Services\ShiftReportManagerReviewService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Verifies the user-directed policy for Task 7.3:
 * "يبقى الأثر المرحّل السابق أثناء انتظار اعتماد التصحيح، ثم يُرحّل الفرق فقط بعد اعتماد البديل."
 * (Source: User directive).
 */
class ShiftShortagePostingApprovalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private CashierShift $cashierShift;

    private BranchManagerShift $managerShift;

    private ShiftReportManagerReviewService $reviewService;

    private ShortagePostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Integration Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $shift->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->managerShift = BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->manager->id, 'shift_date' => today()],
            ['branch_id' => $this->branch->id, 'status' => 'in_progress']
        );

        $this->reviewService = app(ShiftReportManagerReviewService::class);
        $this->postingService = app(ShortagePostingService::class);
    }

    public function test_100_to_120_remains_100_during_waiting_and_posts_plus_20_after_replacement_approval(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        // 1. Initial report with 100.00 SAR shortage (10,000 halalas) approved by manager
        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);

        $this->recordCurrentCashCount($rev1, -10000);
        $this->reviewService->requireFor($this->cashierShift, $rev1, -10000);
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 1, 1, 'Initial approve', 'OP-INIT');

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(1, CashierCustodyTransaction::count());

        // 2. Correction submitted: increases shortage to 120.00 SAR (12,000 halalas)
        // Creates rev2, supersedes alloc1, and creates pending review
        $rev2 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 1);
        $alloc1->update(['superseded_at' => now()]);

        $alloc2 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 2,
            'report_revision' => '2',
            'variance_halalas' => -12000,
            'created_by_type' => 'accountant',
            'created_by_id' => (string) \Illuminate\Support\Str::uuid(),
            'manager_approval_status' => 'pending',
            'supersedes_allocation_id' => $alloc1->id,
        ]);
        $alloc2->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 12000,
        ]);

        $this->recordCurrentCashCount($rev2, -12000);
        $this->reviewService->requireFor($this->cashierShift, $rev2, -12000);

        // Verification of waiting state:
        // - Old liability of 100.00 SAR remains untouched in the ledger
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(1, CashierCustodyTransaction::count());
        $this->assertSame(1, ShiftLiabilityPosting::count());

        // - Workday submit is blocked because replacement review is pending
        try {
            $this->reviewService->assertReadyForWorkday($this->managerShift, [$this->cashierShift->id]);
            $this->fail('Expected ConflictHttpException was not thrown');
        } catch (ConflictHttpException $e) {
            $this->assertSame('BRANCH_MANAGER_REVIEW_REQUIRED', $e->getMessage());
        }

        // 3. Manager approves replacement evidence
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 2, 2, 'Approved corrected report', 'OP-CORRECT-APPROVE');

        // Net delta of +20.00 SAR (+2000 halalas) is posted
        $this->assertSame(2, ShiftLiabilityPosting::count());
        $this->assertSame(2, CashierCustodyTransaction::count());

        $latestPosting = ShiftLiabilityPosting::where('operation_id', 'OP-CORRECT-APPROVE')->first();
        $this->assertNotNull($latestPosting);
        $this->assertSame(2000, $latestPosting->delta_halalas);
        $this->assertSame(12000, $latestPosting->target_halalas);
        $this->assertSame(10000, $latestPosting->effective_before_halalas);

        // Total custody balance is now -120.00 SAR
        $this->assertSame(-120.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));

        // Workday is now unblocked
        $this->reviewService->assertReadyForWorkday($this->managerShift, [$this->cashierShift->id]);
    }

    public function test_manager_cannot_approve_a_missing_or_incomplete_current_allocation(): void
    {
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);
        $allocation = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'accountant',
            'created_by_id' => (string) \Illuminate\Support\Str::uuid(),
            'manager_approval_status' => 'pending',
        ]);
        $this->recordCurrentCashCount($revision, -10000);
        $review = $this->reviewService->requireFor($this->cashierShift, $revision, -10000);

        try {
            $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 1, 1, 'approve', 'INCOMPLETE');
            $this->fail('A manager must not approve an allocation with missing shares.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('ALLOCATION_INCOMPLETE', $exception->getMessage());
        }

        $this->assertSame('pending', $allocation->fresh()->manager_approval_status);
        $this->assertSame('pending', $review->fresh()->status);
    }

    public function test_100_to_0_remains_100_during_waiting_and_posts_minus_100_after_replacement_approval(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        // 1. Initial 100.00 SAR shortage approved
        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);

        $this->recordCurrentCashCount($rev1, -10000);
        $this->reviewService->requireFor($this->cashierShift, $rev1, -10000);
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 1, 1, 'Initial approve', 'OP-INIT-100');

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));

        // 2. Correction to zero variance submitted
        $rev2 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 1);
        $alloc1->update(['superseded_at' => now()]);
        $this->reviewService->requireFor($this->cashierShift, $rev2, 0);

        // During waiting: 100 remains in ledger, workday blocked
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));

        // 3. Manager approves zero variance replacement review
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 2, null, 'Approved zero review', 'OP-ZERO-APPROVE');

        // Net delta of -100.00 SAR (-10,000 halalas) is posted as a full reversal entry
        $this->assertSame(2, ShiftLiabilityPosting::count());
        $latestPosting = ShiftLiabilityPosting::where('operation_id', 'OP-ZERO-APPROVE')->first();
        $this->assertNotNull($latestPosting);
        $this->assertSame(-10000, $latestPosting->delta_halalas);
        $this->assertSame(0, $latestPosting->target_halalas);
        $this->assertSame(10000, $latestPosting->effective_before_halalas);

        // Net balance returned to 0.00 SAR
        $this->assertSame(0.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
    }

    public function test_a100_to_a60_and_b40_posts_a_minus40_and_b_plus40_after_replacement_approval(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        // Initial A: 100
        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);
        $this->recordCurrentCashCount($rev1, -10000);
        $this->reviewService->requireFor($this->cashierShift, $rev1, -10000);
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 1, 1, 'Initial A', 'OP-INIT-A');

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(0.00, $custodyService->getPersonalBalanceOnly($this->cashierB->id));

        // Replaced with A: 60 and B: 40
        $rev2 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 1);
        $alloc1->update(['superseded_at' => now()]);

        $alloc2 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 2,
            'report_revision' => '2',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
            'supersedes_allocation_id' => $alloc1->id,
        ]);
        $alloc2->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 6000,
        ]);
        $alloc2->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierB->id,
            'amount_halalas' => 4000,
        ]);
        $this->recordCurrentCashCount($rev2, -10000);
        $this->reviewService->requireFor($this->cashierShift, $rev2, -10000);

        // Approve replacement
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 2, 2, 'Approve split', 'OP-SPLIT-APPROVE');

        // A is now -60.00, B is now -40.00
        $this->assertSame(-60.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(-40.00, $custodyService->getPersonalBalanceOnly($this->cashierB->id));
    }

    public function test_retry_rejected_correction_and_new_correction_before_approval_do_not_duplicate_postings(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);
        $this->recordCurrentCashCount($rev1, -10000);
        $this->reviewService->requireFor($this->cashierShift, $rev1, -10000);
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 1, 1, 'Initial approve', 'OP-INIT-RETRY');

        $this->assertSame(1, ShiftLiabilityPosting::count());

        // Submitting revision 2
        $rev2 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 1);
        $alloc1->update(['superseded_at' => now()]);
        $alloc2 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 2,
            'report_revision' => '2',
            'variance_halalas' => -12000,
            'created_by_type' => 'accountant',
            'created_by_id' => (string) \Illuminate\Support\Str::uuid(),
            'manager_approval_status' => 'pending',
            'supersedes_allocation_id' => $alloc1->id,
        ]);
        $this->recordCurrentCashCount($rev2, -12000);
        $this->reviewService->requireFor($this->cashierShift, $rev2, -12000);

        // Before revision 2 is approved, revision 3 is submitted (superseding revision 2)
        $rev3 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 2);
        $alloc2->update(['superseded_at' => now()]);
        $alloc3 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 3,
            'report_revision' => '3',
            'variance_halalas' => -15000,
            'created_by_type' => 'accountant',
            'created_by_id' => (string) \Illuminate\Support\Str::uuid(),
            'manager_approval_status' => 'pending',
            'supersedes_allocation_id' => $alloc2->id,
        ]);
        $alloc3->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 15000,
        ]);
        $this->recordCurrentCashCount($rev3, -15000);
        $this->reviewService->requireFor($this->cashierShift, $rev3, -15000);

        // Postings count is STILL 1 (from initial alloc1)
        $this->assertSame(1, ShiftLiabilityPosting::count());

        // Now manager approves rev3 (target 15,000)
        $this->reviewService->approveCurrent($this->cashierShift, $this->manager, 3, 3, 'Approved rev3', 'OP-APPROVE-3');

        // Delta is 15000 - 10000 = +5000 halalas
        $this->assertSame(2, ShiftLiabilityPosting::count());
        $latestPosting = ShiftLiabilityPosting::where('operation_id', 'OP-APPROVE-3')->first();
        $this->assertNotNull($latestPosting);
        $this->assertSame(5000, $latestPosting->delta_halalas);
        $this->assertSame(15000, $latestPosting->target_halalas);
        $this->assertSame(10000, $latestPosting->effective_before_halalas);

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-150.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
    }

    private function recordCurrentCashCount($revision, int $varianceHalalas): void
    {
        ShiftReportCashCount::create([
            'report_revision_id' => $revision->id,
            'counted_revision_id' => $revision->id,
            'evidence_revision_id' => $revision->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => abs($varianceHalalas),
            'cards_halalas' => 0,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 0,
            'expected_halalas' => abs($varianceHalalas),
            'variance_halalas' => $varianceHalalas,
        ]);
    }
}
