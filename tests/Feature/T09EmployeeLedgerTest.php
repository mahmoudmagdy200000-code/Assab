<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Services\ExportService;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T09 §7 ACC-7 — employee account ledger: signed balances, month-bounded
 * statement with a running balance, category-validated movements, «تسوية الرصيد»,
 * and the corrected payroll export math + scope.
 */
class T09EmployeeLedgerTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;   // scope=all

    private AsabUser $scoped;       // scope=branchA only

    private AsabUser $branchUser;   // wrong role

    private Employee $empA;

    private Employee $empB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Ledger Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->user('acc@ledger.test', 'accountant', 'all');
        $this->scoped = $this->user('scoped@ledger.test', 'accountant', 'branch', [$this->branchA->id]);
        $this->branchUser = $this->user('brm@ledger.test', 'branch', 'branch', [$this->branchA->id]);

        $this->empA = $this->employee($this->branchA, '1001', 'محمد', 100000);
        $this->empB = $this->employee($this->branchB, '2001', 'خالد', 80000);
    }

    private function user(string $email, string $role, string $scope, array $branchIds = []): AsabUser
    {
        $u = AsabUser::create(['company_id' => $this->company->id, 'name' => $role, 'email' => $email, 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => $scope, 'branch_ids' => $branchIds]);

        return $u;
    }

    private function employee(Branch $branch, string $num, string $name, int $salary): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id,
            'emp_number' => $num, 'name' => $name, 'role' => 'كاشير',
            'monthly_salary' => $salary, 'status' => 'active',
        ]);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    private function move(Employee $e, string $type, int $amount, string $category, $date = null): EmployeeMovement
    {
        return EmployeeMovement::create([
            'employee_id' => $e->id, 'movement_type' => $type, 'amount' => $amount,
            'category' => $category, 'description' => $category, 'movement_date' => $date ?? now(),
        ]);
    }

    // ── ACC-7.1 list ─────────────────────────────────────────────────────────

    public function test_list_returns_signed_balance_and_name_search(): void
    {
        $this->move($this->empA, 'credit', 10000, 'bonus');
        $this->move($this->empA, 'debit', 3000, 'advance');

        $body = $this->acc()->getJson('/api/v1/company/me/employees')->assertOk()->json();
        $row = collect($body['data'])->firstWhere('id', $this->empA->id);
        $this->assertSame(7000, $row['balanceHalalas']);
        $this->assertSame('فرع أ', $row['branchName']);
        $this->assertArrayHasKey('meta', $body);

        // name search
        $found = $this->acc()->getJson('/api/v1/company/me/employees?q=خالد')->assertOk()->json('data');
        $this->assertSame([$this->empB->id], collect($found)->pluck('id')->all());
    }

    public function test_role_denial_and_unauthenticated(): void
    {
        $this->actingAs($this->branchUser, 'sanctum')->getJson('/api/v1/company/me/employees')->assertStatus(403);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/accountant/employees')->assertStatus(401);
    }

    // ── Tenant + branch isolation ──────────────────────────────────────────────

    public function test_tenant_isolation_hides_other_company_employee(): void
    {
        $otherCo = AsabCompany::create(['name' => 'Other', 'plan' => 'Professional', 'status' => 'active']);
        $otherBrand = AsabBrand::create(['company_id' => $otherCo->id, 'name' => 'ب2', 'sub_status' => 'active', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['asab_brand_id' => $otherBrand->id, 'asab_company_id' => $otherCo->id]);
        $foreign = Employee::create(['company_id' => $otherCo->id, 'branch_id' => $otherBranch->id, 'emp_number' => '9', 'name' => 'x', 'role' => 'كاشير', 'status' => 'active']);

        $this->acc()->getJson("/api/v1/company/me/employees/{$foreign->id}/movements")->assertStatus(404);
    }

    public function test_assigned_branch_scope_hides_other_branch(): void
    {
        $body = $this->actingAs($this->scoped, 'sanctum')->getJson('/api/v1/company/me/employees')->assertOk()->json('data');
        $this->assertSame([$this->empA->id], collect($body)->pluck('id')->all());

        $this->actingAs($this->scoped, 'sanctum')->getJson("/api/v1/company/me/employees/{$this->empB->id}/movements")->assertStatus(404);
    }

    // ── ACC-7.4 add movement ────────────────────────────────────────────────────

    public function test_add_movement_validates_category(): void
    {
        // Unknown category → 422.
        $this->acc()->postJson("/api/v1/accountant/employees/{$this->empA->id}/movements", [
            'movementType' => 'debit', 'amount' => 1000, 'category' => 'nope', 'description' => 'x',
        ])->assertStatus(422);

        // System category is not manually postable → 422.
        $this->acc()->postJson("/api/v1/accountant/employees/{$this->empA->id}/movements", [
            'movementType' => 'debit', 'amount' => 1000, 'category' => 'cash_variance', 'description' => 'x',
        ])->assertStatus(422);

        // Valid manual category → 201 with label + ref.
        $body = $this->acc()->postJson("/api/v1/accountant/employees/{$this->empA->id}/movements", [
            'movementType' => 'debit', 'amount' => 1000, 'category' => 'advance', 'description' => 'سلفة',
        ])->assertStatus(201)->json();
        $this->assertSame('advance', $body['category']);
        $this->assertSame('سلفة', $body['categoryLabelAr']);
        $this->assertStringStartsWith('ADV-', $body['ref']);

        // It appears in the statement.
        $st = $this->acc()->getJson("/api/v1/company/me/employees/{$this->empA->id}/movements")->assertOk()->json();
        $this->assertSame(1, $st['movementCount']);
        $this->assertSame(-1000, $st['balance']);
    }

    // ── ACC-7.2 statement ──────────────────────────────────────────────────────

    public function test_statement_running_balance_and_month_filter(): void
    {
        // Two movements this month → running balance sequence.
        $this->move($this->empA, 'credit', 10000, 'bonus', now()->startOfMonth()->addDays(2));
        $this->move($this->empA, 'debit', 3000, 'advance', now()->startOfMonth()->addDays(4));

        $st = $this->acc()->getJson("/api/v1/company/me/employees/{$this->empA->id}/movements?month=".now()->format('Y-m'))
            ->assertOk()->json();

        $this->assertSame(2, $st['movementCount']);
        $this->assertSame(0, $st['openingBalanceHalalas']);
        $this->assertSame(7000, $st['closingBalanceHalalas']);
        $this->assertFalse($st['autoDeductFromSalary']);
        // Newest-first: first row is the debit carrying running 7000, last the credit at 10000.
        $this->assertSame(7000, $st['movements'][0]['runningBalanceHalalas']);
        $this->assertSame(10000, $st['movements'][1]['runningBalanceHalalas']);

        // A prior-month movement is excluded but carried as opening.
        $this->move($this->empA, 'credit', 5000, 'bonus', now()->subMonthNoOverflow()->startOfMonth()->addDays(3));
        $st2 = $this->acc()->getJson("/api/v1/company/me/employees/{$this->empA->id}/movements?month=".now()->format('Y-m'))
            ->assertOk()->json();
        $this->assertSame(2, $st2['movementCount']);          // still just this month's rows
        $this->assertSame(5000, $st2['openingBalanceHalalas']); // prior month carried
        $this->assertSame(12000, $st2['closingBalanceHalalas']);
    }

    public function test_negative_balance_flags_auto_deduct(): void
    {
        $this->move($this->empA, 'debit', 4000, 'advance');
        $st = $this->acc()->getJson("/api/v1/company/me/employees/{$this->empA->id}/movements")->assertOk()->json();
        $this->assertSame(-4000, $st['balance']);
        $this->assertTrue($st['autoDeductFromSalary']);
        $this->assertSame('مديون للشركة', $st['balanceCaption']['labelAr']);
    }

    // ── ACC-7.3 settle balance ──────────────────────────────────────────────────

    public function test_settle_balance_zeroes_a_debtor_and_is_conflict_when_clear(): void
    {
        $this->move($this->empA, 'debit', 5000, 'advance');

        $body = $this->acc()->postJson("/api/v1/company/me/employees/{$this->empA->id}/settle-balance", [])
            ->assertOk()->json();
        $this->assertSame(0, $body['balanceHalalas']);
        $this->assertSame('credit', $body['movementType']);
        $this->assertSame('settlement', $body['category']);
        $this->assertSame(0, $this->ledgerBalance($this->empA));

        // Already zero → 409.
        $this->acc()->postJson("/api/v1/company/me/employees/{$this->empA->id}/settle-balance", [])
            ->assertStatus(409)->assertJsonPath('error.code', 'BALANCE_ALREADY_SETTLED');
    }

    public function test_settle_balance_partial(): void
    {
        $this->move($this->empA, 'debit', 5000, 'advance');
        $body = $this->acc()->postJson("/api/v1/company/me/employees/{$this->empA->id}/settle-balance", ['amountHalalas' => 2000])
            ->assertOk()->json();
        $this->assertSame(-3000, $body['balanceHalalas']);
        $this->assertSame(-3000, $this->ledgerBalance($this->empA));
    }

    private function ledgerBalance(Employee $e): int
    {
        $credit = (int) EmployeeMovement::where('employee_id', $e->id)->where('movement_type', 'credit')->sum('amount');
        $debit = (int) EmployeeMovement::where('employee_id', $e->id)->where('movement_type', 'debit')->sum('amount');

        return $credit - $debit;
    }

    // ── ACC-7 payroll export math + scope ───────────────────────────────────────

    public function test_payroll_export_math_and_scope(): void
    {
        $month = now()->format('Y-m');
        $this->move($this->empA, 'credit', 20000, 'bonus');   // مكافأة → +net
        $this->move($this->empA, 'debit', 5000, 'advance');   // سلفة   → −net
        $this->move($this->empB, 'credit', 1000, 'bonus');

        // Company-wide (no scope): both employees; empA net = 100000 − 5000 + 20000 = 115000 → 1150.00 SAR.
        $csv = $this->readCsv(app(ExportService::class)->payroll('csv', $month, null));
        $this->assertStringContainsString('1150.00', $csv); // empA net (bonus increased it)
        $this->assertStringContainsString('2001', $csv);    // empB present

        // Branch-scoped to A: empB excluded.
        $scopedCsv = $this->readCsv(app(ExportService::class)->payroll('csv', $month, [$this->branchA->id]));
        $this->assertStringContainsString('1001', $scopedCsv);
        $this->assertStringNotContainsString('2001', $scopedCsv);

        // Endpoint smoke test.
        $this->acc()->get('/api/v1/company/me/employees/payroll/export?format=csv')->assertOk();
    }

    public function test_statement_export_streams(): void
    {
        $this->move($this->empA, 'debit', 3000, 'advance');
        $this->acc()->get("/api/v1/company/me/employees/{$this->empA->id}/statement/export?format=csv")->assertOk();
    }

    private function readCsv($binaryResponse): string
    {
        return (string) file_get_contents($binaryResponse->getFile()->getPathname());
    }
}
