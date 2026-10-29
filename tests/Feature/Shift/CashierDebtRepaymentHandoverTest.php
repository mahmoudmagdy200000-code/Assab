<?php

namespace Tests\Feature\Shift;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftDebtRepayment;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class CashierDebtRepaymentHandoverTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Branch $branch;
    private BranchManager $manager;
    private BranchManager $otherManager;
    private Cashier $cashier;
    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Repayment Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Repayment Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $otherBranch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        // Prior shift with debt: collected 20 SAR of custody
        $this->shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->subDay(),
            'status' => ShiftStatus::COMPLETED,
            'actual_end_time' => now()->subDay(),
            'cash_collected' => 20.00,
            'total_sales' => 20.00,
            'card_payments' => 0,
            'closing_balance' => 20.00,
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->shift, 'cashier', $this->cashier->id, 0);
        DB::transaction(fn () => app(ShiftCashCountService::class)->record($this->shift, $revision, 2000, 0, 0, 2000));

        BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'active',
        ]);

        // Prior deficit/debt: 20 SAR custody debit (owed by cashier)
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Shortage Liability',
            'amount' => 20.00,
            'is_cash_in' => true,
            'related_shift_id' => $this->shift->id,
            'transaction_date' => now()->subDay(),
        ]);
    }

    private function cashierDebtBalance(): float
    {
        $in = (float) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->where('is_cash_in', true)->sum('amount');
        $out = (float) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->where('is_cash_in', false)->sum('amount');

        return round($in - $out, 2);
    }

    public function test_cashier_submits_debt_repayment_handover_status_pending(): void
    {
        $this->assertEquals(20.00, $this->cashierDebtBalance());

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/shifts/repayments', [
                'amountHalalas' => 500, // 5.00 SAR
                'managerId' => $this->manager->id,
                'notes' => 'Partial shortage repayment for Shift #89',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'status' => 'pending_manager_confirmation',
        ]);

        $repayment = ShiftDebtRepayment::where('cashier_id', $this->cashier->id)->first();
        $this->assertNotNull($repayment);
        $this->assertEquals('pending_manager_confirmation', $repayment->status);
        $this->assertEquals(500, $repayment->amount_halalas);

        // Before manager confirmation, debt balance is UNTOUCHED (zero balance movement)
        $this->assertEquals(20.00, $this->cashierDebtBalance());
    }

    public function test_cannot_submit_debt_repayment_when_cashier_has_zero_debt(): void
    {
        // Fully clear debt first
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Full Settlement',
            'amount' => 20.00,
            'is_cash_in' => false,
            'transaction_date' => now(),
        ]);
        $this->assertEquals(0.00, $this->cashierDebtBalance());

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/shifts/repayments', [
                'amountHalalas' => 500,
                'managerId' => $this->manager->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_submit_debt_repayment_exceeding_outstanding_debt(): void
    {
        $this->assertEquals(20.00, $this->cashierDebtBalance());

        // Attempt to repay 25.00 SAR (2500 halalas) when debt is 20.00 SAR (2000 halalas)
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/shifts/repayments', [
                'amountHalalas' => 2500,
                'managerId' => $this->manager->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_submission_replay_returns_existing_pending_repayment(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/shifts/repayments', [
                'amountHalalas' => 500,
                'managerId' => $this->manager->id,
            ])
            ->assertStatus(201);

        $countBefore = ShiftDebtRepayment::count();
        $this->assertEquals(1, $countBefore);

        // Replay same submission
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/shifts/repayments', [
                'amountHalalas' => 500,
                'managerId' => $this->manager->id,
            ]);

        $response->assertStatus(201);
        $this->assertEquals($countBefore, ShiftDebtRepayment::count());
    }

    public function test_non_assigned_manager_cannot_confirm_repayment(): void
    {
        $repayment = ShiftDebtRepayment::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'manager_id' => $this->manager->id,
            'amount_halalas' => 500,
            'status' => 'pending_manager_confirmation',
        ]);

        $response = $this->actingAs($this->otherManager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$repayment->id}/confirm");

        $response->assertStatus(403);
    }

    public function test_manager_confirms_partial_debt_repayment_reduces_debt_without_sales_or_report_reopen(): void
    {
        $revisionsCountBefore = ShiftReportRevision::count();
        $salesBefore = $this->shift->fresh()->only(['total_sales', 'cash_collected', 'card_payments', 'status']);

        $repayment = ShiftDebtRepayment::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'manager_id' => $this->manager->id,
            'amount_halalas' => 500, // 5.00 SAR repayment
            'status' => 'pending_manager_confirmation',
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$repayment->id}/confirm");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'confirmed',
            'amountHalalas' => 500,
            'remainingDebtHalalas' => 1500, // 15.00 SAR remaining (AC-40)
        ]);

        $repayment->refresh();
        $this->assertEquals('confirmed', $repayment->status);
        $this->assertEquals($this->manager->id, $repayment->confirmed_by_id);
        $this->assertNotNull($repayment->confirmed_at);

        // Cashier debt is reduced by 5.00 SAR -> 15.00 SAR remains
        $this->assertEquals(15.00, $this->cashierDebtBalance());

        // E07: Verify debt_repayment_id linkage in cashier custody transaction
        $cashierTx = CashierCustodyTransaction::where('cashier_id', $this->cashier->id)
            ->where('category', 'debt_repayment')
            ->first();
        $this->assertNotNull($cashierTx);
        $this->assertEquals($repayment->id, $cashierTx->debt_repayment_id);
        $this->assertFalse((bool) $cashierTx->is_cash_in);
        $this->assertEquals('5.00', (string) $cashierTx->amount);

        // E07: Verify debt_repayment_id linkage in manager personal ledger
        $managerTx = PersonalLedgerTransaction::where('branch_manager_id', $this->manager->id)
            ->where('category', 'debt_repayment')
            ->first();
        $this->assertNotNull($managerTx);
        $this->assertEquals($repayment->id, $managerTx->debt_repayment_id);
        $this->assertTrue((bool) $managerTx->is_cash_in);
        $this->assertEquals('5.00', (string) $managerTx->amount);

        // Report was NOT reopened and no new sales created (COR-09)
        $this->assertEquals($revisionsCountBefore, ShiftReportRevision::count());
        $this->assertEquals($salesBefore, $this->shift->fresh()->only(['total_sales', 'cash_collected', 'card_payments', 'status']));
    }

    public function test_manager_confirms_sequential_partial_repayments_down_to_zero(): void
    {
        // Repayment 1: 5.00 SAR
        $rep1 = ShiftDebtRepayment::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'manager_id' => $this->manager->id,
            'amount_halalas' => 500,
            'status' => 'pending_manager_confirmation',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$rep1->id}/confirm")
            ->assertStatus(200)
            ->assertJson([
                'remainingDebtHalalas' => 1500,
            ]);
        $this->assertEquals(15.00, $this->cashierDebtBalance());

        // Repayment 2: 15.00 SAR
        $rep2 = ShiftDebtRepayment::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'manager_id' => $this->manager->id,
            'amount_halalas' => 1500,
            'status' => 'pending_manager_confirmation',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$rep2->id}/confirm")
            ->assertStatus(200)
            ->assertJson([
                'remainingDebtHalalas' => 0,
            ]);
        $this->assertEquals(0.00, $this->cashierDebtBalance());
    }

    public function test_manager_confirms_complete_debt_repayment_reaches_zero(): void
    {
        $repayment = ShiftDebtRepayment::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'manager_id' => $this->manager->id,
            'amount_halalas' => 2000, // 20.00 SAR complete repayment (AC-41)
            'status' => 'pending_manager_confirmation',
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$repayment->id}/confirm");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'confirmed',
            'amountHalalas' => 2000,
            'remainingDebtHalalas' => 0,
        ]);

        // Cashier debt is now 0.00 SAR
        $this->assertEquals(0.00, $this->cashierDebtBalance());
    }

    public function test_cannot_confirm_already_confirmed_repayment(): void
    {
        $repayment = ShiftDebtRepayment::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'manager_id' => $this->manager->id,
            'amount_halalas' => 500,
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/shifts/repayments/{$repayment->id}/confirm");

        $response->assertStatus(409);
    }
}
