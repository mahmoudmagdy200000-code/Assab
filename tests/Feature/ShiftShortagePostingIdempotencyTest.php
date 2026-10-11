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
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityLegacyPostingLink;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
use Tests\TestCase;

class ShiftShortagePostingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $cashierShift;

    private ShortagePostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Idempotency Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shift->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->postingService = app(ShortagePostingService::class);
    }

    public function test_reconciling_same_target_repeatedly_produces_zero_deltas(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        $alloc = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
        ]);
        $alloc->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'amount_halalas' => 10000,
        ]);

        // First execution: posts 10,000 delta
        $res1 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-IDEMP-1');
        $this->assertSame(1, $res1['total_deltas']);
        $this->assertSame(1, ShiftLiabilityPosting::count());
        $this->assertSame(1, CashierCustodyTransaction::count());

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashier->id));

        // Retry 1: same target with same operation ID
        $res2 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-IDEMP-1');
        $this->assertSame(0, $res2['total_deltas']);
        $this->assertSame(1, ShiftLiabilityPosting::count());
        $this->assertSame(1, CashierCustodyTransaction::count());
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashier->id));

        // Retry 2: same target with a NEW operation ID (e.g. separate reconciliation command)
        $res3 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-IDEMP-2');
        $this->assertSame(0, $res3['total_deltas']);
        $this->assertSame(1, ShiftLiabilityPosting::count());
        $this->assertSame(1, CashierCustodyTransaction::count());
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashier->id));
    }

    public function test_legacy_cashier_liability_is_linked_before_modern_reconciliation(): void
    {
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => '100.00',
            'is_cash_in' => false,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);
        $allocation = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id, 'version' => 1,
            'report_revision' => '1', 'variance_halalas' => -10000,
            'created_by_type' => 'branch_manager', 'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved', 'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
        ]);
        $allocation->shares()->create([
            'responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id,
            'amount_halalas' => 10000,
        ]);

        $result = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'LEGACY-TARGET-100');

        $this->assertSame(0, $result['total_deltas']);
        $this->assertSame(0, ShiftLiabilityPosting::count());
        $this->assertSame(1, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame(10000, ShiftLiabilityLegacyPostingLink::sole()->signed_halalas);
        $this->assertSame(-100.00, app(CashierCustodyService::class)->getPersonalBalanceOnly($this->cashier->id));

        $allocation->update(['superseded_at' => now()]);
        $replacement = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id, 'version' => 2,
            'report_revision' => '1', 'variance_halalas' => -12000,
            'created_by_type' => 'branch_manager', 'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved', 'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(), 'supersedes_allocation_id' => $allocation->id,
        ]);
        $replacement->shares()->create([
            'responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id,
            'amount_halalas' => 12000,
        ]);
        $delta = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 2, 'LEGACY-TARGET-120');
        $this->assertSame(1, $delta['total_deltas']);
        $this->assertSame(2000, $delta['postings'][0]->delta_halalas);
        $this->assertSame(10000, $delta['postings'][0]->effective_before_halalas);
    }

    public function test_legacy_cashier_liability_reverses_only_the_difference_when_target_is_lower(): void
    {
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => '100.00',
            'is_cash_in' => false,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);
        $allocation = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id, 'version' => 1,
            'report_revision' => '1', 'variance_halalas' => -6000,
            'created_by_type' => 'branch_manager', 'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved', 'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
        ]);
        $allocation->shares()->create([
            'responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id,
            'amount_halalas' => 6000,
        ]);

        $result = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'LEGACY-TARGET-60');

        $this->assertSame(1, $result['total_deltas']);
        $this->assertSame(-4000, $result['postings'][0]->delta_halalas);
        $this->assertSame(10000, $result['postings'][0]->effective_before_halalas);
        $this->assertSame(-60.00, app(CashierCustodyService::class)->getPersonalBalanceOnly($this->cashier->id));
    }
}
