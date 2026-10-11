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
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityLegacyPostingLink;
use Modules\Shift\Services\ShiftLiabilityLegacyPostingLinkService;
use Tests\TestCase;

class ShiftLiabilityLegacyPostingTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Employee $employee;

    private CashierShift $cashierShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Legacy Test Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => '1001',
            'name' => 'Ali',
            'role' => 'كاشير',
            'monthly_salary' => 500000,
            'status' => 'active',
        ]);

        $shift = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Evening',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shift->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);
    }

    public function test_legacy_employee_movement_linked_without_mutating_or_deleting_original(): void
    {
        $movement = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 15000, // 150.00 SAR
            'category' => 'cash_variance',
            'description' => 'خصم فرق كاش سابق',
            'movement_date' => now(),
        ]);

        $initialAttrs = $movement->fresh()->getAttributes();

        $service = app(ShiftLiabilityLegacyPostingLinkService::class);
        $link = $service->linkLegacyRow(
            $this->cashierShift,
            'employee',
            $this->employee->id,
            'asab_employee_movements',
            $movement->id,
            15000,
            ['notes' => 'Imported from pre-lineage run']
        );

        $this->assertInstanceOf(ShiftLiabilityLegacyPostingLink::class, $link);
        $this->assertSame(15000, $link->signed_halalas);
        $this->assertSame("{$this->cashierShift->id}:employee:{$this->employee->id}", $link->lineage_key);

        // Original row must remain completely identical
        $this->assertEquals($initialAttrs, $movement->fresh()->getAttributes());
    }

    public function test_legacy_link_enforces_unique_per_ledger_row(): void
    {
        $custodyTxn = CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => 100.00,
            'is_cash_in' => false,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        $service = app(ShiftLiabilityLegacyPostingLinkService::class);
        $service->linkLegacyRow(
            $this->cashierShift,
            'cashier',
            $this->cashier->id,
            'cashier_custody_transactions',
            $custodyTxn->id,
            10000
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Duplicate link for the same ledger row must fail
        $service->linkLegacyRow(
            $this->cashierShift,
            'cashier',
            $this->cashier->id,
            'cashier_custody_transactions',
            $custodyTxn->id,
            10000
        );
    }

    public function test_soft_deleted_legacy_record_remains_tracked_and_prevents_silent_reposting(): void
    {
        $custodyTxn = CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => 100.00,
            'is_cash_in' => false,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        $service = app(ShiftLiabilityLegacyPostingLinkService::class);
        $service->linkLegacyRow(
            $this->cashierShift,
            'cashier',
            $this->cashier->id,
            'cashier_custody_transactions',
            $custodyTxn->id,
            10000
        );

        // Soft-delete the custody transaction
        $custodyTxn->delete();
        $this->assertTrue($custodyTxn->fresh()->trashed());

        // Effective already posted must still include the linked legacy row (tracked)
        $lineageKey = "{$this->cashierShift->id}:cashier:{$this->cashier->id}";
        $effective = $service->resolveEffectivePostedHalalas($lineageKey);
        $this->assertSame(10000, $effective);
    }

    public function test_employee_movement_sign_convention_debit_charges_and_credit_reverses(): void
    {
        // 1. Charge 100.00 SAR (10,000 halalas) via debit
        $charge = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 10000,
            'category' => 'cash_variance',
            'description' => 'خصم فرق كاش',
            'movement_date' => now(),
        ]);

        // Net employee balance: credit - debit
        // Since employee owes 10,000, balanceHalalas is -10000 (liability)
        $debits = EmployeeMovement::where('employee_id', $this->employee->id)->where('movement_type', 'debit')->sum('amount');
        $credits = EmployeeMovement::where('employee_id', $this->employee->id)->where('movement_type', 'credit')->sum('amount');
        $balance = $credits - $debits;
        $this->assertSame(-10000, $balance);

        // 2. Reverse 30.00 SAR (3,000 halalas) via credit
        $reversal = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'credit',
            'amount' => 3000,
            'category' => 'cash_variance',
            'description' => 'رد فرق كاش',
            'movement_date' => now(),
        ]);

        $debits = EmployeeMovement::where('employee_id', $this->employee->id)->where('movement_type', 'debit')->sum('amount');
        $credits = EmployeeMovement::where('employee_id', $this->employee->id)->where('movement_type', 'credit')->sum('amount');
        $this->assertSame(-7000, $credits - $debits);
        $this->assertSame(7000, $debits - $credits); // Signed liability net is positive 7000
    }

    public function test_cashier_custody_sign_convention_reduces_and_restores_balance(): void
    {
        $custodyService = app(CashierCustodyService::class);

        // Starting balance: 0.00
        $this->assertSame(0.00, $custodyService->getPersonalBalanceOnly($this->cashier->id));

        // Cashier charges 100.00 SAR shortage (is_cash_in = false)
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => 100.00,
            'is_cash_in' => false,
            'category' => 'cash_variance',
            'transaction_date' => now(),
        ]);

        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashier->id));

        // Cashier reversal of 40.00 SAR shortage (is_cash_in = true)
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => 40.00,
            'is_cash_in' => true,
            'category' => 'cash_variance',
            'transaction_date' => now(),
        ]);

        $this->assertSame(-60.00, $custodyService->getPersonalBalanceOnly($this->cashier->id));
    }

    public function test_manager_personal_ledger_sign_convention(): void
    {
        $personalService = app(PersonalLedgerService::class);

        // Starting balance: 0.00
        $this->assertSame(0.00, $personalService->getPersonalBalanceOnly($this->manager->id));

        // Manager charges 200.00 SAR shortage (is_cash_in = false)
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Manager Variance',
            'amount' => 200.00,
            'is_cash_in' => false,
            'category' => 'cash_variance',
            'transaction_date' => now(),
        ]);

        $this->assertSame(-200.00, $personalService->getPersonalBalanceOnly($this->manager->id));

        // Reversal of 50.00 SAR (is_cash_in = true)
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Manager Variance',
            'amount' => 50.00,
            'is_cash_in' => true,
            'category' => 'cash_variance',
            'transaction_date' => now(),
        ]);

        $this->assertSame(-150.00, $personalService->getPersonalBalanceOnly($this->manager->id));
    }
}
