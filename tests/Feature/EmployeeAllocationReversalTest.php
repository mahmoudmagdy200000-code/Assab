<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\EmployeeAllocationService;
use Modules\Admin\Services\EmployeeLedgerService;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * BE-07 (AUD-01) — Reversal Entries without Deleting Financial Movements
 * In reverse(), instead of deleting records with ->delete(), insert reversing
 * credit entries opposite each debit movement to maintain complete audit trail.
 */
class EmployeeAllocationReversalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabBrand $brand;
    private Branch $branch;
    private AsabUser $accountant;
    private Employee $emp1;
    private Employee $emp2;
    private EmployeeAllocationService $service;
    private EmployeeLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Test Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'Branch A', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant',
            'email' => 'acc@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        $this->emp1 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => '101',
            'name' => 'Ahmed',
            'role' => 'cashier',
            'status' => 'active',
        ]);

        $this->emp2 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => '102',
            'name' => 'Salem',
            'role' => 'supervisor',
            'status' => 'active',
        ]);

        $this->service = app(EmployeeAllocationService::class);
        $this->ledger = app(EmployeeLedgerService::class);
    }

    private function makeOp(string $prefix = 'WD', string $module = 'waste'): Operation
    {
        return Operation::create([
            'public_id' => OperationSequence::next($prefix),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => $module,
            'amount' => 30000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => [],
        ]);
    }

    public function test_reverse_inserts_reversing_credit_entries_instead_of_deleting(): void
    {
        $op = $this->makeOp();
        $category = 'waste_charge';
        $categoryLabelAr = 'خصم هدر';

        // Post allocations: emp1 = 20,000 halalas, emp2 = 10,000 halalas
        $this->service->post($op, $category, $categoryLabelAr, [
            ['employee' => $this->emp1, 'amountHalalas' => 20000],
            ['employee' => $this->emp2, 'amountHalalas' => 10000],
        ], $this->accountant);

        // Verify initial state: 2 debits, 0 credits
        $this->assertSame(2, EmployeeMovement::where('movement_type', 'debit')->count());
        $this->assertSame(0, EmployeeMovement::where('movement_type', 'credit')->count());
        $this->assertSame(-20000, $this->ledger->currentBalance($this->emp1->id));
        $this->assertSame(-10000, $this->ledger->currentBalance($this->emp2->id));

        // Execute reverse()
        $reversedCount = $this->service->reverse($op, $category);

        $this->assertSame(2, $reversedCount);

        // Crucial AUD-01 assertions: original debits are NOT deleted!
        $this->assertSame(2, EmployeeMovement::where('movement_type', 'debit')->count(), 'Debit records must not be deleted');
        $this->assertSame(4, EmployeeMovement::count(), 'Total records must include 2 debits and 2 compensating credits');

        // Compensating credits were created
        $credits = EmployeeMovement::where('movement_type', 'credit')
            ->where('ref_operation_id', $op->id)
            ->where('category', $category)
            ->get();
        $this->assertCount(2, $credits);

        $emp1Credit = $credits->firstWhere('employee_id', $this->emp1->id);
        $this->assertNotNull($emp1Credit);
        $this->assertSame(20000, (int) $emp1Credit->amount);

        $emp2Credit = $credits->firstWhere('employee_id', $this->emp2->id);
        $this->assertNotNull($emp2Credit);
        $this->assertSame(10000, (int) $emp2Credit->amount);

        // Standing balance after reversal must be 0 for both employees
        $this->assertSame(0, $this->ledger->currentBalance($this->emp1->id));
        $this->assertSame(0, $this->ledger->currentBalance($this->emp2->id));
    }

    public function test_reverse_is_idempotent_and_does_not_create_duplicate_credits(): void
    {
        $op = $this->makeOp();
        $category = 'waste_charge';

        $this->service->post($op, $category, 'خصم هدر', [
            ['employee' => $this->emp1, 'amountHalalas' => 15000],
        ], $this->accountant);

        $this->assertSame(1, $this->service->reverse($op, $category));
        $this->assertSame(2, EmployeeMovement::count()); // 1 debit, 1 credit

        // Second reverse call must be a no-op
        $this->assertSame(0, $this->service->reverse($op, $category));
        $this->assertSame(2, EmployeeMovement::count()); // still 1 debit, 1 credit
        $this->assertSame(0, $this->ledger->currentBalance($this->emp1->id));
    }
}
