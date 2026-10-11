<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\HandoverSettlementService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Tests\TestCase;

class ShiftHandoverSettlementBalancesTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $cashierShift;

    private BranchManagerShift $managerShift;

    private CashierShiftHandoverReceipt $receipt;

    private HandoverSettlementService $settlementService;

    private ShiftTransferReceiptService $receiptService;

    protected function setUp(): void
    {
        parent::setUp();
        // Technical policy simulation; production writer remains inactive.
        config(['shift.settlement_authority_approved' => true]);

        $this->company = AsabCompany::create(['name' => 'Balance Test Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Standard Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
        $this->managerShift->update(['status' => 'completed']);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'shift_id' => $this->cashierShift->shift_id,
            'branch_id' => $this->branch->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => 100.00,
            'declared_amount' => 100.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->cashierShift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashier->id,
        ]);

        ShiftReportCashCount::create([
            'report_revision_id' => $revision->id,
            'counted_revision_id' => $revision->id,
            'evidence_revision_id' => $revision->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 0,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 10000,
            'expected_halalas' => 10000,
            'variance_halalas' => 0,
            'counted_by_type' => 'cashier',
            'counted_by_id' => $this->cashier->id,
            'counted_at' => now(),
        ]);

        $this->receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => null,
            'receiving_cashier_id' => null,
            'receiving_branch_manager_shift_id' => $this->managerShift->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'confirmed_by_branch_manager_id' => $this->manager->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => 100.00,
            'confirmed_at' => now(),
        ]);

        // Original confirmed sales cash into manager personal ledger
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '100.00',
            'is_cash_in' => true,
            'cashier_name' => $this->cashier->name,
            'related_shift_id' => $this->managerShift->id,
            'related_handover_id' => $handover->id,
            'receipt_id' => $this->receipt->id,
            'transaction_date' => now(),
        ]);

        $this->settlementService = app(HandoverSettlementService::class);
        \Modules\Custody\Models\CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id, 'transaction_type' => 'Total Sales',
            'amount' => '100.00', 'is_cash_in' => true, 'related_shift_id' => $this->cashierShift->id, 'transaction_date' => now(),
        ]);
        $this->receiptService = app(ShiftTransferReceiptService::class);
    }

    public function test_manager_available_cash_tracks_overpayment_return(): void
    {
        // Initial available cash: 100.00 SAR (10,000 halalas)
        $this->assertEquals(10000, $this->receiptService->availableManagerCashMinor($this->manager->id));

        // Execute 25.00 SAR overpayment return (manager surrenders excess 25 SAR to cashier)
        $this->settlementService->settle(
            $this->receipt,
            $this->manager,
            'overpayment_return',
            2500, // 25.00 SAR
            0,
            'Overpayment 25 SAR returned to cashier',
            'bal-op-return-1'
        );

        // Manager available cash must now be 75.00 SAR (7,500 halalas)
        $this->assertEquals(7500, $this->receiptService->availableManagerCashMinor($this->manager->id));

        // Receipt original amount remains 100.00 SAR
        $this->assertEquals('100.00', $this->receipt->fresh()->confirmed_amount);
    }

    public function test_manager_available_cash_tracks_underpayment_supplement(): void
    {
        // Initial available cash: 100.00 SAR (10,000 halalas)
        $this->assertEquals(10000, $this->receiptService->availableManagerCashMinor($this->manager->id));

        // Execute 15.00 SAR underpayment supplement (cashier pays 15 SAR shortfall to manager)
        $this->settlementService->settle(
            $this->receipt,
            $this->manager,
            'underpayment_supplement',
            1500, // 15.00 SAR
            0,
            'Shortfall 15 SAR paid by cashier to manager',
            'bal-op-supp-1'
        );

        // Manager available cash must now be 115.00 SAR (11,500 halalas)
        $this->assertEquals(11500, $this->receiptService->availableManagerCashMinor($this->manager->id));
    }

    public function test_two_consecutive_settlements_reflect_sequentially(): void
    {
        // Initial: 10,000 halalas
        $this->assertEquals(10000, $this->receiptService->availableManagerCashMinor($this->manager->id));

        // S1: return 20.00 SAR -> 8,000 halalas
        $this->settlementService->settle(
            $this->receipt,
            $this->manager,
            'overpayment_return',
            2000,
            0,
            'First return 20 SAR',
            'bal-seq-1'
        );
        $this->assertEquals(8000, $this->receiptService->availableManagerCashMinor($this->manager->id));

        // S2: supplement 5.00 SAR -> 8,500 halalas
        $this->settlementService->settle(
            $this->receipt,
            $this->manager,
            'underpayment_supplement',
            500,
            1,
            'Supplement 5 SAR',
            'bal-seq-2'
        );
        $this->assertEquals(8500, $this->receiptService->availableManagerCashMinor($this->manager->id));
    }

    public function test_variance_claims_do_not_inflate_available_cash(): void
    {
        // Initial: 10,000 halalas
        $this->assertEquals(10000, $this->receiptService->availableManagerCashMinor($this->manager->id));

        // Record a variance claim row (is_cash_in = true, but transaction_type = Variance from Cashier)
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Variance from Cashier',
            'amount' => '50.00',
            'is_cash_in' => true,
            'cashier_name' => $this->cashier->name,
            'related_shift_id' => $this->managerShift->id,
            'transaction_date' => now(),
        ]);

        // Still 10,000 halalas! Claims are NOT counted as sales cash!
        $this->assertEquals(10000, $this->receiptService->availableManagerCashMinor($this->manager->id));
    }

    public function test_opening_balance_and_historical_count_remain_immutable(): void
    {
        $count = ShiftReportCashCount::where('report_revision_id', $this->receipt->report_revision_id)->first();
        $this->assertNotNull($count);
        $originalCountHalalas = $count->counted_halalas;

        // Perform settlement
        $this->settlementService->settle(
            $this->receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'Audit count immutability test',
            'bal-count-imm'
        );

        // Historical count record has not changed
        $this->assertEquals($originalCountHalalas, $count->fresh()->counted_halalas);
        $this->assertEquals('100.00', $this->receipt->fresh()->confirmed_amount);
    }
}
