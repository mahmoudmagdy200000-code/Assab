<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Shift as AdminShift;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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

    public function test_legacy_reconciliation_ignores_unrelated_rows_and_blocks_foreign_actor_provenance(): void
    {
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Handover Received',
            'amount' => 100.00,
            'is_cash_in' => true,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);
        $service = app(ShiftLiabilityLegacyPostingLinkService::class);
        $service->reconcileShiftLegacyEvidence($this->cashierShift);
        $this->assertSame(0, ShiftLiabilityLegacyPostingLink::count());

        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $foreignCashier = Cashier::factory()->create(['branch_id' => $otherBranch->id]);
        CashierCustodyTransaction::create([
            'cashier_id' => $foreignCashier->id,
            'transaction_type' => 'Variance',
            'amount' => 100.00,
            'is_cash_in' => false,
            'category' => 'cash_variance',
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('LEGACY_LIABILITY_RECONCILIATION_REQUIRED');
        $service->reconcileShiftLegacyEvidence($this->cashierShift);
    }

    public function test_reconciliation_links_cashier_and_employee_liability_but_not_manager_custody_mirror(): void
    {
        $cashierRow = CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Variance',
            'amount' => '100.00',
            'is_cash_in' => false,
            'category' => 'cash_variance',
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);
        $managerRow = PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Variance from Cashier',
            'amount' => '50.00',
            'is_cash_in' => false,
            'category' => 'cash_variance',
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);
        $adminShift = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_name' => $this->cashier->name,
            'role' => 'cashier',
            'shift_type' => 'day',
            'started_at' => now(),
            'status' => 'completed',
            'legacy_shift_id' => $this->cashierShift->id,
        ]);
        $operationId = (string) Str::uuid();
        DB::table('asab_operations')->insert([
            'id' => $operationId,
            'public_id' => 'OP-'.strtoupper(Str::random(10)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'shifts',
            'source_module' => 'shifts',
            'source_id' => $adminShift->id,
            'payload' => json_encode([]),
            'amount' => 15000,
            'status' => 'final-approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeRow = EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 15000,
            'category' => 'cash_variance',
            'description' => 'Legacy employee cash variance',
            'ref_operation_id' => $operationId,
            'movement_date' => now(),
        ]);
        $originalEmployee = $employeeRow->fresh()->getAttributes();

        app(ShiftLiabilityLegacyPostingLinkService::class)->reconcileShiftLegacyEvidence($this->cashierShift);

        $this->assertSame(2, ShiftLiabilityLegacyPostingLink::count());
        $this->assertSame(10000, ShiftLiabilityLegacyPostingLink::where('ledger_table', 'cashier_custody_transactions')
            ->where('ledger_id', $cashierRow->id)->sole()->signed_halalas);
        $this->assertSame(0, ShiftLiabilityLegacyPostingLink::where('ledger_table', 'personal_ledger_transactions')
            ->where('ledger_id', $managerRow->id)->count());
        $this->assertSame(15000, ShiftLiabilityLegacyPostingLink::where('ledger_table', 'asab_employee_movements')
            ->where('ledger_id', $employeeRow->id)->sole()->signed_halalas);
        $this->assertEquals($originalEmployee, $employeeRow->fresh()->getAttributes());
    }
}
