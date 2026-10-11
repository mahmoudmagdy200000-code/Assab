<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftLiabilityPostingState;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftShortagePostingTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Employee $employee;

    private CashierShift $cashierShift;

    private ShortagePostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Shortage Posting Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => '1001',
            'name' => 'Employee 1',
            'role' => 'كاشير',
            'monthly_salary' => 500000,
            'status' => 'active',
        ]);

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

        $this->postingService = app(ShortagePostingService::class);
    }

    public function test_sequence_10000_to_12000_to_7000_to_0_posts_exact_deltas(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        // Step 1: Initial approved allocation of 100.00 SAR (10,000 halalas) to Cashier A
        $alloc1 = ShiftLiabilityAllocation::create([
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
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);

        $res1 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-STEP-1');
        $this->assertCount(1, $res1['postings']);
        $this->assertSame(10000, $res1['postings'][0]->delta_halalas);
        $this->assertSame(10000, $res1['postings'][0]->target_halalas);
        $this->assertSame(0, $res1['postings'][0]->effective_before_halalas);

        $this->assertSame(1, CashierCustodyTransaction::where('cashier_id', $this->cashierA->id)->count());
        $txn1 = CashierCustodyTransaction::where('cashier_id', $this->cashierA->id)->first();
        $this->assertSame('100.00', (string) $txn1->amount);
        $this->assertFalse($txn1->is_cash_in); // cash deduction / charge

        // Step 2: Target increases to 120.00 SAR (12,000 halalas)
        $alloc1->update(['superseded_at' => now()]);
        $alloc2 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 2,
            'report_revision' => '1',
            'variance_halalas' => -12000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
            'supersedes_allocation_id' => $alloc1->id,
        ]);
        $alloc2->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 12000,
        ]);

        $res2 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 2, 'OP-STEP-2');
        $this->assertCount(1, $res2['postings']);
        $this->assertSame(2000, $res2['postings'][0]->delta_halalas); // +20.00 SAR delta
        $this->assertSame(12000, $res2['postings'][0]->target_halalas);
        $this->assertSame(10000, $res2['postings'][0]->effective_before_halalas);

        $this->assertSame(2, CashierCustodyTransaction::where('cashier_id', $this->cashierA->id)->count());

        // Step 3: Target decreases to 70.00 SAR (7,000 halalas) -> delta is -5000 halalas (-50.00 SAR)
        $alloc2->update(['superseded_at' => now()]);
        $alloc3 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 3,
            'report_revision' => '1',
            'variance_halalas' => -7000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
            'supersedes_allocation_id' => $alloc2->id,
        ]);
        $alloc3->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 7000,
        ]);

        $res3 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 3, 'OP-STEP-3');
        $this->assertCount(1, $res3['postings']);
        $this->assertSame(-5000, $res3['postings'][0]->delta_halalas); // -50.00 SAR reversal delta
        $this->assertSame(7000, $res3['postings'][0]->target_halalas);
        $this->assertSame(12000, $res3['postings'][0]->effective_before_halalas);

        $txn3 = CashierCustodyTransaction::find($res3['postings'][0]->ledger_id);
        $this->assertSame('50.00', (string) $txn3->amount);
        $this->assertTrue($txn3->is_cash_in); // reversal is cash in

        // Step 4: Target becomes 0 (shortage eliminated) -> delta is -7000 halalas (full reversal)
        $alloc3->update(['superseded_at' => now()]);
        $alloc4 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 4,
            'report_revision' => '1',
            'variance_halalas' => 0,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
            'supersedes_allocation_id' => $alloc3->id,
        ]);
        // Zero variance: no shares created

        $res4 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 4, 'OP-STEP-4');
        $this->assertCount(1, $res4['postings']);
        $this->assertSame(-7000, $res4['postings'][0]->delta_halalas); // -70.00 SAR full net reversal
        $this->assertSame(0, $res4['postings'][0]->target_halalas);
        $this->assertSame(7000, $res4['postings'][0]->effective_before_halalas);

        // Net effective liability for Cashier A is now precisely 0
        $lineageKey = "{$this->cashierShift->id}:cashier:{$this->cashierA->id}";
        $netPosted = ShiftLiabilityPosting::where('lineage_key', $lineageKey)->sum('delta_halalas');
        $this->assertSame(0, $netPosted);
    }

    public function test_redistribution_a100_to_a60_and_b40_posts_a_minus40_and_b_plus40(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        // Step 1: 100.00 SAR on Cashier A alone
        $alloc1 = ShiftLiabilityAllocation::create([
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
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);

        $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-SPLIT-1');

        // Step 2: Redistribution to Cashier A: 60.00 SAR (6,000 halalas) and Cashier B: 40.00 SAR (4,000 halalas)
        $alloc1->update(['superseded_at' => now()]);
        $alloc2 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 2,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->manager->id,
            'manager_approved_at' => now(),
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

        $res2 = $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 2, 'OP-SPLIT-2');
        $this->assertCount(2, $res2['postings']);

        $keyA = "{$this->cashierShift->id}:cashier:{$this->cashierA->id}";
        $keyB = "{$this->cashierShift->id}:cashier:{$this->cashierB->id}";

        $postingA = collect($res2['postings'])->firstWhere('lineage_key', $keyA);
        $postingB = collect($res2['postings'])->firstWhere('lineage_key', $keyB);

        $this->assertNotNull($postingA);
        $this->assertNotNull($postingB);

        // A was 100, now 60 -> delta is -4000
        $this->assertSame(-4000, $postingA->delta_halalas);
        $this->assertSame(6000, $postingA->target_halalas);
        $this->assertSame(10000, $postingA->effective_before_halalas);

        // B was 0, now 40 -> delta is +4000
        $this->assertSame(4000, $postingB->delta_halalas);
        $this->assertSame(4000, $postingB->target_halalas);
        $this->assertSame(0, $postingB->effective_before_halalas);
    }

    public function test_reconciliation_fails_if_allocation_is_still_pending_approval(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

        $alloc = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -5000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending', // NOT approved
        ]);
        $alloc->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 5000,
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('BRANCH_LIABILITY_APPROVAL_PENDING');

        $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-PENDING');
    }

    public function test_transaction_rollback_preserves_byte_equivalent_state_on_failure(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashierA->id, 0);

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
            'responsible_type' => 'unsupported_type', // Will throw InvalidArgumentException inside postToLedgerAdapter
            'responsible_id' => (string) \Illuminate\Support\Str::uuid(),
            'amount_halalas' => 10000,
        ]);

        try {
            $this->postingService->reconcile($this->cashierShift, $this->manager, 1, 1, 'OP-FAIL');
            $this->fail('Expected exception was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported responsible type', $e->getMessage());
        }

        // Entire transaction must be rolled back: zero postings, zero posting states, zero ledger entries
        $this->assertSame(0, ShiftLiabilityPosting::count());
        $this->assertSame(0, ShiftLiabilityPostingState::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
        $this->assertSame(0, EmployeeMovement::count());
    }
}
