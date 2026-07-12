<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T04.2 — «الفرق يُخصم من حساب المسؤول».
 *
 * A shortfall is a NEGATIVE variance; before T04 the full-allocation guard
 * compared `variance > 0` and never fired on it, so partial allocations were
 * silently accepted and the employee ledger under-collected the gap.
 */
class SalesVarianceAllocationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    private Employee $cashier;

    private Employee $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Variance Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@variance.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->cashier = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => '1001', 'name' => 'محمد العتيبي', 'role' => 'كاشير رئيسي', 'status' => 'active',
        ]);
        $this->supervisor = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => '1002', 'name' => 'خالد الشمري', 'role' => 'مشرف الشفت', 'status' => 'active',
        ]);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /** A sales op reconciled to a 350.00 SAR shortfall (−35,000 halalas). */
    private function shortfallOp(array $attrs = []): Operation
    {
        $op = Operation::create(array_merge([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'sales',
            'amount' => 1000000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
        ], $attrs));

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 965000]],
        ])->assertOk();

        return $op->fresh();
    }

    public function test_a_partial_allocation_of_a_shortfall_is_refused_and_posts_nothing(): void
    {
        $op = $this->shortfallOp();

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 20000]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.messageAr', 'يجب أن يساوي مجموع التخصيصات قيمة الفارق');

        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_an_over_allocation_is_refused(): void
    {
        $op = $this->shortfallOp();

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 40000]],
        ])->assertStatus(422);

        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_a_full_allocation_posts_categorised_debits_atomically(): void
    {
        $op = $this->shortfallOp();

        $body = $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [
                ['empNumber' => '1001', 'amountHalalas' => 20000],
                ['empNumber' => '1002', 'amountHalalas' => 15000],
            ],
            'notes' => 'فرق قناة جاهز',
        ])->assertOk()->json();

        $this->assertSame(35000, $body['varianceTotalHalalas']);
        $this->assertSame(0, $body['remainingUnallocatedHalalas']);
        $this->assertSame(0, $body['remainingVarianceHalalas']);
        $this->assertSame('فرق مبيعات', $body['allocations'][0]['categoryLabelAr']);

        $movements = EmployeeMovement::orderBy('amount')->get();
        $this->assertCount(2, $movements);
        foreach ($movements as $movement) {
            $this->assertSame('debit', $movement->movement_type);
            $this->assertSame('sales_variance', $movement->category);
            $this->assertSame($op->id, $movement->ref_operation_id);
            $this->assertStringStartsWith('فرق مبيعات — '.$op->public_id, $movement->description);
        }
        $this->assertSame(35000, (int) $movements->sum('amount'));

        // Read-back for the detail screen.
        $payload = $op->fresh()->payload;
        $this->assertCount(2, $payload['varianceAllocations']);
        $this->assertSame('فرق قناة جاهز', $payload['varianceNotes']);
    }

    public function test_a_locked_operation_cannot_receive_allocations(): void
    {
        $op = $this->shortfallOp();
        $op->update(['status' => Operation::STATUS_FINAL]);

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 35000]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');

        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_an_unknown_employee_rolls_the_whole_allocation_back(): void
    {
        $op = $this->shortfallOp();

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [
                ['empNumber' => '1001', 'amountHalalas' => 20000],
                ['empNumber' => '9999', 'amountHalalas' => 15000],
            ],
        ])->assertStatus(422);

        // The first movement must not survive the failed second one.
        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_an_employee_of_another_branch_is_refused(): void
    {
        $otherBranch = Branch::factory()->create(['name' => 'فرع آخر', 'asab_company_id' => $this->company->id]);
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $otherBranch->id,
            'emp_number' => '2001', 'name' => 'موظف آخر', 'role' => 'كاشير', 'status' => 'active',
        ]);
        $op = $this->shortfallOp();

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '2001', 'amountHalalas' => 35000]],
        ])->assertStatus(422);

        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_an_overage_is_allocated_by_its_magnitude_too(): void
    {
        $op = Operation::create([
            'public_id' => OperationSequence::next('OPS'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'module_key' => 'sales', 'amount' => 1000000, 'match' => 'exact', 'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 1010000]],
        ])->assertOk();

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 5000]],
        ])->assertStatus(422);

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 10000]],
        ])->assertOk()->assertJsonPath('varianceTotalHalalas', 10000);
    }

    public function test_employee_lookup_fills_the_name(): void
    {
        $this->acc()
            ->getJson("/api/v1/company/me/branches/{$this->branch->id}/employees/lookup?empNumber=1001")
            ->assertOk()
            ->assertJsonPath('name', 'محمد العتيبي');

        $this->acc()
            ->getJson("/api/v1/company/me/branches/{$this->branch->id}/employees/lookup?empNumber=9999")
            ->assertStatus(404);
    }
}
