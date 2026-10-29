<?php

namespace Tests\Feature\Accountant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\EmployeeAllocationService;
use Modules\Admin\Services\EmployeeLedgerService;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * BE-15 (AUD-04): Financial Command Idempotency Audit & Gaps Closure.
 *
 * Verifies E02a (Header vs Body idempotency key unification and 409 on changed payload),
 * E02b (Accountant / Company route aliases idempotency parity),
 * and E02c (EmployeeAllocationService once-only idempotent retry without duplicate movements).
 */
class FinancialCommandIdempotencyAuditTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Branch $branch;
    private AsabUser $accountant;
    private Employee $emp1;
    private Employee $emp2;
    private EmployeeAllocationService $allocationService;
    private EmployeeLedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Aud04 Test Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Aud04 Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Aud04 Accountant',
            'email' => 'aud04@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $this->emp1 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => 'AUD-001',
            'name' => 'Employee One',
            'role' => 'cashier',
            'status' => 'active',
        ]);

        $this->emp2 = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => 'AUD-002',
            'name' => 'Employee Two',
            'role' => 'cashier',
            'status' => 'active',
        ]);

        // Give both employees starting debit balances
        EmployeeMovement::create([
            'employee_id' => $this->emp1->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'cash_variance',
            'description' => 'Initial debit emp 1',
            'movement_date' => now(),
        ]);

        EmployeeMovement::create([
            'employee_id' => $this->emp2->id,
            'movement_type' => 'debit',
            'amount' => 5000,
            'category' => 'cash_variance',
            'description' => 'Initial debit emp 2',
            'movement_date' => now(),
        ]);

        $this->allocationService = app(EmployeeAllocationService::class);
        $this->ledgerService = app(EmployeeLedgerService::class);
    }

    public function test_body_idempotency_key_with_identical_payload_replays_cached_response(): void
    {
        $key = (string) Str::uuid();

        // 1. First submission with body idempotencyKey
        $res1 = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'idempotencyKey' => $key,
                'amountHalalas' => 2000,
                'reason' => 'Initial salary adjustment',
            ]);

        $res1->assertStatus(201);
        $reqId = $res1->json('data.id') ?? $res1->json('id');
        $this->assertNotNull($reqId);

        // Exactly 1 settlement request created
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());

        // 2. Replay with same body idempotencyKey and identical payload
        $res2 = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'idempotencyKey' => $key,
                'amountHalalas' => 2000,
                'reason' => 'Initial salary adjustment',
            ]);

        $this->assertTrue(in_array($res2->status(), [200, 201], true));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    public function test_body_idempotency_key_with_changed_payload_returns_409(): void
    {
        $key = (string) Str::uuid();

        // 1. First call: amount = 1000
        $res1 = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'idempotencyKey' => $key,
                'amountHalalas' => 1000,
                'reason' => 'First payload',
            ]);

        $res1->assertStatus(201);

        // 2. Second call: same key, CHANGED payload: amount = 2000
        // E02a: MUST return 409, NOT 201!
        $res2 = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'idempotencyKey' => $key,
                'amountHalalas' => 2000,
                'reason' => 'Changed payload',
            ]);

        $res2->assertStatus(409);
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    public function test_header_and_body_keys_differing_returns_422(): void
    {
        $headerKey = (string) Str::uuid();
        $bodyKey = (string) Str::uuid();

        $res = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $headerKey)
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'idempotencyKey' => $bodyKey,
                'amountHalalas' => 1000,
                'reason' => 'Mismatched keys',
            ]);

        $res->assertStatus(422);
    }

    public function test_same_key_across_company_and_accountant_aliases_shares_idempotency(): void
    {
        $key = (string) Str::uuid();

        // 1. Post to /company/me/...
        $res1 = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'amountHalalas' => 1500,
                'reason' => 'Cross alias test',
            ]);

        $res1->assertStatus(201);
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());

        // 2. Post identical payload to /accountant/... alias with the SAME key
        $res2 = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/accountant/employees/{$this->emp1->id}/settle-balance", [
                'amountHalalas' => 1500,
                'reason' => 'Cross alias test',
            ]);

        $this->assertTrue(in_array($res2->status(), [200, 201], true));
        // Still strictly 1 record created
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    public function test_same_key_for_different_employee_resource_returns_409(): void
    {
        $key = (string) Str::uuid();

        // Call for employee 1
        $res1 = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$this->emp1->id}/settle-balance", [
                'amountHalalas' => 1000,
                'reason' => 'Emp 1 settle',
            ]);

        $res1->assertStatus(201);

        // Same key for employee 2 MUST return 409 conflict, NOT employee 1's settlement
        $res2 = $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$this->emp2->id}/settle-balance", [
                'amountHalalas' => 1000,
                'reason' => 'Emp 2 settle',
            ]);

        $res2->assertStatus(409);
    }

    public function test_allocation_service_once_only_does_not_duplicate_movements_on_identical_retry(): void
    {
        $op = Operation::create([
            'public_id' => OperationSequence::next('WD'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'waste',
            'amount' => 3000,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => [],
        ]);

        // 1. Initial allocation: charges emp1 2000, emp2 1000
        $this->allocationService->post($op, 'waste_charge', 'خصم هدر', [
            ['employee' => $this->emp1, 'amountHalalas' => 2000],
            ['employee' => $this->emp2, 'amountHalalas' => 1000],
        ], $this->accountant);

        // Initial state: 2 debits for this operation
        $this->assertSame(2, EmployeeMovement::where('ref_operation_id', $op->id)->where('movement_type', 'debit')->count());
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $op->id)->where('movement_type', 'credit')->count());

        // 2. Identical retry: must return existing movements WITHOUT creating reversing credits or extra debits (E02c)
        $this->allocationService->post($op, 'waste_charge', 'خصم هدر', [
            ['employee' => $this->emp1, 'amountHalalas' => 2000],
            ['employee' => $this->emp2, 'amountHalalas' => 1000],
        ], $this->accountant);

        // Verification: movement counts MUST remain strictly 2 debits and 0 credits
        $this->assertSame(2, EmployeeMovement::where('ref_operation_id', $op->id)->where('movement_type', 'debit')->count(), 'No duplicate debits on identical retry');
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $op->id)->where('movement_type', 'credit')->count(), 'No compensating credits created on identical retry');
    }
}
