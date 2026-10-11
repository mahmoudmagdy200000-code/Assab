<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Policies\HandoverSettlementActorPolicy;
use Modules\Shift\Services\HandoverSettlementService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ShiftHandoverSettlementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private BranchManager $managerBranchA;

    private BranchManager $managerBranchB;

    private Cashier $cashier;

    private CashierShift $shift;

    private CashierShiftHandoverReceipt $receipt;

    private HandoverSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Technical policy simulation; production writer remains inactive.
        config(['shift.settlement_authority_approved' => true]);

        $this->company = AsabCompany::create(['name' => 'Auth Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->managerBranchA = BranchManager::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerBranchB = BranchManager::factory()->create(['branch_id' => $this->branchB->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branchA->id]);

        $shiftDef = Shift::create([
            'branch_id' => $this->branchA->id,
            'name' => 'Shift A',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branchA->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $managerWorkday = BranchManagerShift::where('branch_manager_id', $this->managerBranchA->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->managerBranchA->id,
                'branch_id' => $this->branchA->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
        $managerWorkday->update(['status' => 'completed']);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'shift_id' => $this->shift->shift_id,
            'branch_id' => $this->branchA->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerBranchA->id,
            'handover_amount' => 100.00,
            'declared_amount' => 100.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->shift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashier->id,
        ]);

        $this->receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => null,
            'receiving_cashier_id' => null,
            'receiving_branch_manager_shift_id' => $managerWorkday->id,
            'receiving_branch_manager_id' => $this->managerBranchA->id,
            'confirmed_by_branch_manager_id' => $this->managerBranchA->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => 100.00,
            'confirmed_at' => now(),
        ]);

        $this->service = app(HandoverSettlementService::class);
        \Modules\Custody\Models\PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->managerBranchA->id, 'transaction_type' => 'Total Sales',
            'amount' => '100.00', 'is_cash_in' => true, 'receipt_id' => $this->receipt->id,
            'related_shift_id' => $managerWorkday->id, 'transaction_date' => now(),
        ]);
    }

    public function test_assigned_active_manager_of_receipt_branch_is_authorized(): void
    {
        $settlement = $this->service->settle(
            $this->receipt,
            $this->managerBranchA,
            'overpayment_return',
            1000,
            0,
            'Authorized manager settlement',
            'op-auth-ok'
        );

        $this->assertInstanceOf(ShiftHandoverSettlement::class, $settlement);
        $this->assertEquals($this->managerBranchA->id, $settlement->actor_id);
    }

    public function test_manager_from_different_branch_is_rejected(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->service->settle(
            $this->receipt,
            $this->managerBranchB, // Manager of Branch B, not Branch A
            'overpayment_return',
            1000,
            0,
            'Cross branch attempt',
            'op-cross-branch'
        );

        // Verify zero writes
        $this->assertEquals(0, ShiftHandoverSettlement::count());
        $this->assertEquals(0, CashierCustodyTransaction::where('category', 'handover_settlement')->count());
        $this->assertEquals(0, PersonalLedgerTransaction::where('category', 'handover_settlement')->count());
    }

    public function test_cashier_cannot_act_as_settlement_actor(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('UNAUTHORIZED_SETTLEMENT_ACTOR');

        $this->service->settle(
            $this->receipt,
            $this->cashier, // Cashier is not authorized
            'overpayment_return',
            1000,
            0,
            'Cashier attempt',
            'op-cashier-auth'
        );

        // Verify zero writes
        $this->assertEquals(0, ShiftHandoverSettlement::count());
        $this->assertEquals(0, CashierCustodyTransaction::where('category', 'handover_settlement')->count());
        $this->assertEquals(0, PersonalLedgerTransaction::where('category', 'handover_settlement')->count());
    }

    public function test_policy_documents_unresolved_authority_constant(): void
    {
        $this->assertEquals('SETTLEMENT_AUTHORITY_UNRESOLVED', HandoverSettlementActorPolicy::SETTLEMENT_AUTHORITY_UNRESOLVED);
    }
}
