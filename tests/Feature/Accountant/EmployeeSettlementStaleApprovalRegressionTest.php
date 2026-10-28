<?php

namespace Tests\Feature\Accountant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\EmployeeSettlementRequest;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * BE-09 P1/P2 regression coverage for the audit-confirmed defects
 * (REVIEWER_EXECUTED 2026-10-26, Mahmoud's scenario):
 *
 * P1 — approving a stale settlement request must fail safely without
 *      introducing a new financial movement or recreating debt.
 * P2 — same idempotency key + different payload must return the existing
 *      contract's 409 conflict; cross-employee replay must not leak.
 * P3 — omitted amount idempotency replay preserves original intent and
 *      does not compare against fluctuating balance.
 */
class EmployeeSettlementStaleApprovalRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private Branch $branch;
    private AsabUser $accountant;
    private AsabUser $headAccountant;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Stale Reg Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع رئيسي', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->createUser('acc@stale.test', 'accountant');
        $this->headAccountant = $this->createUser('head@stale.test', 'head');

        $this->employee = $this->createEmployee('1001', 'أحمد كاشير');
    }

    /** Mahmoud's scenario: -5000 balance, two pending 5000 requests. */
    public function test_stale_second_approval_is_rejected_without_new_movement(): void
    {
        $this->assertBalance(-5000);

        $req1 = $this->pendingRequest(5000);
        $req2 = $this->pendingRequest(5000);

        // First approval: clears the -5000 debt to 0.
        $res1 = $this->approve($req1);
        $res1->assertOk();
        $this->assertSame(0, $res1->json('balanceHalalas'));
        $this->assertSame('approved', $req1->fresh()->status);
        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertBalance(0);

        // Second approval: balance is now 0; must reject 409 without posting.
        $res2 = $this->approve($req2);
        $res2->assertStatus(409);
        $this->assertSame('BALANCE_ALREADY_SETTLED', $res2->json('error.code'));
        $this->assertSame('pending', $req2->fresh()->status);
        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertBalance(0);
    }

    public function test_stale_approval_on_partial_debt_exhaustion_is_rejected(): void
    {
        $this->assertBalance(-5000);

        $req1 = $this->pendingRequest(4000);
        $req2 = $this->pendingRequest(3000);

        // First approval clears 4000 halalas, leaving -1000 balance.
        $res1 = $this->approve($req1);
        $res1->assertOk();
        $this->assertSame(-1000, $res1->json('balanceHalalas'));
        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertBalance(-1000);

        // Second approval requested 3000, but only 1000 debt remains: reject 409.
        $res2 = $this->approve($req2);
        $res2->assertStatus(409);
        $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $res2->json('error.code'));
        $this->assertSame('pending', $req2->fresh()->status);
        $this->assertSame(1, $this->settlementMovementCount());
        $this->assertBalance(-1000);
    }

    public function test_stale_approval_when_balance_direction_flips_is_rejected(): void
    {
        $this->assertBalance(-5000);

        $req = $this->pendingRequest(5000);

        // External movement turns employee into a creditor (+3000).
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'credit',
            'amount' => 8000,
            'category' => 'settlement',
            'ref' => 'EXT-OVERPAY',
            'description' => 'سداد خارجي فائق',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->assertBalance(3000);

        // Approval of the original debtor-clearing request must reject 409.
        $res = $this->approve($req);
        $res->assertStatus(409);
        $this->assertSame('SETTLEMENT_DIRECTION_CHANGED', $res->json('error.code'));
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertBalance(3000);
    }

    public function test_body_idempotency_key_different_payload_is_rejected_409(): void
    {
        $key = (string) Str::uuid();

        $res1 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'amountHalalas' => 1000,
            'reason' => 'تسوية مبدئية',
        ]);
        $res1->assertStatus(201);

        $res2 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'amountHalalas' => 2000,
            'reason' => 'تسوية مبدئية',
        ]);
        $res2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $res2->json('error.code'));

        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
        $this->assertSame(0, $this->settlementMovementCount());
    }

    public function test_omitted_amount_replay_against_explicit_amount_is_rejected_409(): void
    {
        $key = (string) Str::uuid();

        // Original request specifies explicit amount (partial: 2000 halalas).
        $res1 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'amountHalalas' => 2000,
            'reason' => 'تسوية جزئية',
        ]);
        $res1->assertStatus(201);

        // Replay omits amount (intending full balance): must conflict 409.
        $res2 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'reason' => 'تسوية جزئية',
        ]);
        $res2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $res2->json('error.code'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    public function test_explicit_amount_replay_against_omitted_amount_is_rejected_409(): void
    {
        $key = (string) Str::uuid();

        // Original request omits amount (full balance: 5000 halalas).
        $res1 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'reason' => 'تسوية رصيد كامل',
        ]);
        $res1->assertStatus(201);

        // Replay provides explicit amount: must conflict 409 because original intent was full settlement.
        $res2 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'amountHalalas' => 5000,
            'reason' => 'تسوية رصيد كامل',
        ]);
        $res2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $res2->json('error.code'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    public function test_omitted_amount_replay_against_omitted_amount_replays_successfully(): void
    {
        $key = (string) Str::uuid();

        // Original request: amount omitted (full balance = 5000 halalas).
        $res1 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'reason' => 'تسوية رصيد كامل',
        ]);
        $res1->assertStatus(201);
        $this->assertSame(5000, $res1->json('amountHalalas'));
        $this->assertTrue($res1->json('isFullSettlement'));

        // Replay: same key, same reason, amount omitted.
        $res2 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'reason' => 'تسوية رصيد كامل',
        ]);
        $res2->assertStatus(201);
        $this->assertSame($res1->json('id'), $res2->json('id'));
        $this->assertSame(5000, $res2->json('amountHalalas'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
    }

    /**
     * Requirement 3: When amount was omitted at creation, idempotent replay
     * must preserve the original intent and SUCCEED without error, even if the
     * employee's balance changed between the original request and the replay.
     */
    public function test_omitted_amount_replay_succeeds_even_when_balance_fluctuated_after_creation(): void
    {
        $key = (string) Str::uuid();

        // 1. Original request: amount omitted (full balance = 5000 halalas snapshot).
        $res1 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'reason' => 'تسوية رصيد كامل قبل تغير الرصيد',
        ]);
        $res1->assertStatus(201);
        $this->assertSame(5000, $res1->json('amountHalalas'));
        $this->assertTrue($res1->json('isFullSettlement'));

        // 2. An additional debit movement occurs, changing employee standing balance from -5000 to -8000.
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 3000,
            'category' => 'advance',
            'description' => 'سلفة إضافية طارئة',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->assertBalance(-8000);

        // 3. Replay with same key, same reason, amount omitted:
        // Because the schema captures is_full_settlement = true, the replay matches intent
        // without comparing against the current balance (-8000). It must return the original request!
        $res2 = $this->settleViaBody($this->employee, [
            'idempotencyKey' => $key,
            'reason' => 'تسوية رصيد كامل قبل تغير الرصيد',
        ]);
        $res2->assertStatus(201);
        $this->assertSame($res1->json('id'), $res2->json('id'));
        $this->assertSame(5000, $res2->json('amountHalalas'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
        $this->assertSame(0, $this->settlementMovementCount());
    }

    public function test_header_key_used_for_another_employee_does_not_leak_or_create(): void
    {
        $other = $this->createEmployee('2002', 'سعيد كاشير', -4000);
        $key = (string) Str::uuid();

        $resA = $this->settleViaBody($this->employee, ['amountHalalas' => 5000, 'reason' => 'تسوية أ'], $key);
        $resA->assertStatus(201);

        $resB = $this->settleViaBody($other, ['amountHalalas' => 4000, 'reason' => 'تسوية ب'], $key);
        $resB->assertStatus(409);

        // No leak of A's resource and no new request for B under A's key.
        $this->assertNotSame($resA->json('id'), $resB->json('id'));
        $this->assertSame(1, EmployeeSettlementRequest::where('idempotency_key', $key)->count());
        $this->assertSame(1, EmployeeSettlementRequest::where('employee_id', $this->employee->id)->count());
        $this->assertSame(0, EmployeeSettlementRequest::where('employee_id', $other->id)->count());
    }

    public function test_same_key_in_another_tenant_is_isolated(): void
    {
        $company2 = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand2 = AsabBrand::create(['company_id' => $company2->id, 'name' => 'براند ٢', 'status' => 'active']);
        $branch2 = Branch::factory()->create(['name' => 'فرع ٢', 'asab_brand_id' => $brand2->id, 'asab_company_id' => $company2->id]);
        $acc2 = $this->createUser('acc2@stale.test', 'accountant', $company2);
        $emp2 = Employee::create([
            'company_id' => $company2->id,
            'branch_id' => $branch2->id,
            'emp_number' => '3001',
            'name' => 'كاشير شركة أخرى',
            'role' => 'كاشير',
            'monthly_salary' => 90000,
            'status' => 'active',
        ]);
        EmployeeMovement::create([
            'employee_id' => $emp2->id,
            'movement_type' => 'debit',
            'amount' => 2500,
            'category' => 'advance',
            'description' => 'سلفة',
            'movement_date' => now(),
            'created_by_id' => $acc2->id,
        ]);

        $key = (string) Str::uuid();
        $res1 = $this->settleViaBody($this->employee, ['amountHalalas' => 5000, 'reason' => 'شركة أ'], $key);
        $res1->assertStatus(201);

        $res2 = $this->actingAs($acc2, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/company/me/employees/{$emp2->id}/settle-balance", [
                'amountHalalas' => 2500,
                'reason' => 'شركة ب',
            ]);

        $res2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $res2->json('error.code'));
        $this->assertNotSame($res1->json('id'), $res2->json('id'));
        $this->assertSame(1, DB::table('asab_employee_settlement_requests')->where('idempotency_key', $key)->count());
        $this->assertSame(0, DB::table('asab_employee_settlement_requests')->where('employee_id', $emp2->id)->count());
    }

    /**
     * Point 2: When full settlement was intended (is_full_settlement=true),
     * if the balance debt INCREASES (e.g. from -5000 to -7000), approval MUST NOT
     * perform an implicit partial settlement. It must reject with 409 SETTLEMENT_BALANCE_CHANGED.
     */
    public function test_full_settlement_rejected_when_balance_debt_increases_before_approval(): void
    {
        $this->assertBalance(-5000);

        // Full settlement requested (amount omitted)
        $res = $this->settleViaBody($this->employee, [
            'reason' => 'تسوية رصيد بالكامل',
        ])->assertStatus(201);
        $req = EmployeeSettlementRequest::findOrFail($res->json('id'));
        $this->assertTrue($req->is_full_settlement);
        $this->assertSame(5000, (int) $req->amount_halalas);

        // Debt increases before approval from -5000 to -7000 (new advance)
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 2000,
            'category' => 'advance',
            'description' => 'سلفة إضافية',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->assertBalance(-7000);

        // Approval must reject with 409 to prevent implicit partial settlement
        $resApprove = $this->approve($req);
        $resApprove->assertStatus(409);
        $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resApprove->json('error.code'));
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertSame(0, $this->settlementMovementCount());
        $this->assertBalance(-7000);
    }

    /**
     * Point 2: When full settlement was intended (is_full_settlement=true),
     * if the balance debt DECREASES (e.g. from -5000 to -3000), approval MUST reject
     * with 409 SETTLEMENT_BALANCE_CHANGED rather than over-settling.
     */
    public function test_full_settlement_rejected_when_balance_debt_decreases_before_approval(): void
    {
        $this->assertBalance(-5000);

        $res = $this->settleViaBody($this->employee, [
            'reason' => 'تسوية رصيد بالكامل قبل السداد',
        ])->assertStatus(201);
        $req = EmployeeSettlementRequest::findOrFail($res->json('id'));
        $this->assertTrue($req->is_full_settlement);

        // Debt decreases before approval from -5000 to -3000 (external credit)
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'credit',
            'amount' => 2000,
            'category' => 'settlement',
            'ref' => 'EXT-PARTIAL',
            'description' => 'سداد جزئي مباشر',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->assertBalance(-3000);

        // Approval must reject with 409 SETTLEMENT_BALANCE_CHANGED
        $resApprove = $this->approve($req);
        $resApprove->assertStatus(409);
        $this->assertSame('SETTLEMENT_BALANCE_CHANGED', $resApprove->json('error.code'));
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertSame(1, $this->settlementMovementCount()); // Only the external one exists
        $this->assertBalance(-3000);
    }

    /**
     * Contrast: Partial settlement (is_full_settlement=false) allows approval when debt
     * increased (still covers the partial amount), but rejects when debt drops below the partial amount.
     */
    public function test_partial_settlement_allowed_when_debt_increases_but_fails_when_debt_drops_below_amount(): void
    {
        $this->assertBalance(-5000);

        // Explicit partial settlement of 2000 halalas
        $res = $this->settleViaBody($this->employee, [
            'amountHalalas' => 2000,
            'reason' => 'تسوية جزئية محددة',
        ])->assertStatus(201);
        $req = EmployeeSettlementRequest::findOrFail($res->json('id'));
        $this->assertFalse($req->is_full_settlement);

        // Debt increases from -5000 to -7000
        EmployeeMovement::create([
            'employee_id' => $this->employee->id,
            'movement_type' => 'debit',
            'amount' => 2000,
            'category' => 'advance',
            'description' => 'سلفة',
            'movement_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->assertBalance(-7000);

        // Partial settlement of 2000 succeeds because debt still covers 2000
        $resApprove = $this->approve($req)->assertOk();
        $this->assertSame(-5000, $resApprove->json('balanceHalalas'));
        $this->assertBalance(-5000);
    }

    // ------------------------------------------------------------------

    private function createEmployee(string $empNumber, string $name, ?int $debit = 5000): Employee
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => $empNumber,
            'name' => $name,
            'role' => 'كاشير',
            'monthly_salary' => 100000,
            'status' => 'active',
        ]);

        if ($debit !== null) {
            EmployeeMovement::create([
                'employee_id' => $employee->id,
                'movement_type' => 'debit',
                'amount' => $debit,
                'category' => 'advance',
                'description' => 'سلفة مؤقتة',
                'movement_date' => now(),
                'created_by_id' => $this->accountant->id,
            ]);
        }

        return $employee;
    }

    private function createUser(string $email, string $role, ?AsabCompany $company = null): AsabUser
    {
        $company = $company ?? $this->company;
        $u = AsabUser::create([
            'company_id' => $company->id,
            'name' => $role,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all']);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $u->id, 'role_key' => $role, 'status' => 'active']);

        return $u;
    }

    private function pendingRequest(int $amount): EmployeeSettlementRequest
    {
        return EmployeeSettlementRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'amount_halalas' => $amount,
            'is_full_settlement' => false,
            'original_balance_halalas' => -5000,
            'direction' => 'credit',
            'reason' => 'سداد نقدي',
            'status' => 'pending',
            'created_by_user_id' => $this->accountant->id,
        ]);
    }

    private function settleViaBody(Employee $employee, array $payload, ?string $headerKey = null)
    {
        $call = $this->actingAs($this->accountant, 'sanctum');
        if ($headerKey !== null) {
            $call = $call->withHeader('Idempotency-Key', $headerKey);
        }

        return $call->postJson("/api/v1/company/me/employees/{$employee->id}/settle-balance", $payload);
    }

    private function approve(EmployeeSettlementRequest $request)
    {
        return $this->actingAs($this->headAccountant, 'sanctum')
            ->postJson("/api/v1/company/me/employee-settlements/{$request->id}/approve");
    }

    private function settlementMovementCount(): int
    {
        return EmployeeMovement::where('employee_id', $this->employee->id)->where('category', 'settlement')->count();
    }

    private function assertBalance(int $expected): void
    {
        $balance = (int) EmployeeMovement::where('employee_id', $this->employee->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END), 0) as bal")
            ->value('bal');
        $this->assertSame($expected, $balance);
    }
}
