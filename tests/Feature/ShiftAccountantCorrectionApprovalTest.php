<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\ShiftReportManagerReviewService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftAccountantCorrectionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Shift $shiftTemplate;

    private Cashier $cashier;

    private BranchManager $manager;

    private CashierShift $cashierShift;

    private ShiftReportRevisionService $revisionService;

    private ShiftReportManagerReviewService $reviewService;

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

        $this->cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
        $this->reviewService = app(ShiftReportManagerReviewService::class);
    }

    public function test_require_for_creates_pending_review_and_sets_aggregate_pointer(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $review = $this->reviewService->requireFor($this->cashierShift, $rev1, varianceHalalas: -5000, reason: 'Accountant variance adjustment');

        $this->assertInstanceOf(ShiftReportManagerReview::class, $review);
        $this->assertSame('pending', $review->status);
        $this->assertSame(-5000, $review->variance_halalas);
        $this->assertTrue($review->has_shortage);
        $this->assertSame($rev1->id, $review->report_revision_id);

        $aggregate = ShiftReportAggregate::where('source_id', $this->cashierShift->id)->first();
        $this->assertSame($rev1->id, $aggregate->required_manager_review_evidence_revision_id);
    }

    public function test_manager_approves_current_evidence_with_zero_variance(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        $this->reviewService->requireFor($this->cashierShift, $rev1, varianceHalalas: 0, reason: 'Zero variance correction');

        $approved = $this->reviewService->approveCurrent(
            $this->cashierShift,
            $this->manager,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Manager approved zero variance',
            operationId: (string) Str::uuid()
        );

        $this->assertSame('approved', $approved->status);
        $this->assertSame((string) $this->manager->id, (string) $approved->reviewer_id);
        $this->assertNotNull($approved->reviewed_at);

        // Zero variance does NOT create zero liability allocation
        $this->assertDatabaseMissing('shift_liability_allocations', [
            'cashier_shift_id' => $this->cashierShift->id,
        ]);
    }

    public function test_unassigned_manager_cannot_approve(): void
    {
        $otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        $this->reviewService->requireFor($this->cashierShift, $rev1, varianceHalalas: 0);

        $this->expectException(AccessDeniedHttpException::class);
        $this->reviewService->approveCurrent(
            $this->cashierShift,
            $otherManager,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Foreign approval',
            operationId: (string) Str::uuid()
        );
    }

    public function test_pending_manager_review_blocks_workday_submission(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        $this->reviewService->requireFor($this->cashierShift, $rev1, varianceHalalas: 0);

        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->firstOrFail();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('BRANCH_MANAGER_REVIEW_REQUIRED');

        app(BranchManagerShiftService::class)->lockCashierFinancialInputs($workday);
    }

    public function test_approved_review_allows_workday_submission(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        $this->reviewService->requireFor($this->cashierShift, $rev1, varianceHalalas: 0);

        $this->reviewService->approveCurrent(
            $this->cashierShift,
            $this->manager,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Approved',
            operationId: (string) Str::uuid()
        );

        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->firstOrFail();

        // Should not throw
        app(BranchManagerShiftService::class)->lockCashierFinancialInputs($workday);
        $this->assertTrue(true);
    }

    public function test_new_correction_invalidates_prior_approval_for_workday_gate_without_deleting_old_review(): void
    {
        // 1. Rev 1 created & approved
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        $this->reviewService->requireFor($this->cashierShift, $rev1, varianceHalalas: 0);
        $review1 = $this->reviewService->approveCurrent(
            $this->cashierShift,
            $this->manager,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Approved rev 1',
            operationId: (string) Str::uuid()
        );
        $this->assertSame('approved', $review1->status);

        // 2. Correction creates Rev 2 with new financial evidence
        $rev2 = $this->revisionService->recordCashierRevision($this->cashierShift, 'accountant', (string) Str::uuid(), expectedRevision: 1);
        $review2 = $this->reviewService->requireFor($this->cashierShift, $rev2, varianceHalalas: -2000, reason: 'Rev 2 shortage');

        // Review 1 is still in DB as approved (historical immutability)
        $this->assertDatabaseHas('shift_report_manager_reviews', [
            'id' => $review1->id,
            'status' => 'approved',
        ]);

        // Aggregate points to Rev 2 which is pending
        $aggregate = ShiftReportAggregate::where('source_id', $this->cashierShift->id)->first();
        $this->assertSame($rev2->id, $aggregate->required_manager_review_evidence_revision_id);

        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->firstOrFail();
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('BRANCH_MANAGER_REVIEW_REQUIRED');

        app(BranchManagerShiftService::class)->lockCashierFinancialInputs($workday);
    }
}
