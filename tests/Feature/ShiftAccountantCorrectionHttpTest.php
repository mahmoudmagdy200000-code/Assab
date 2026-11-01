<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ShiftAccountantCorrectionHttpTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Cashier $cashier;

    private BranchManager $manager;

    private CashierShift $cashierShift;

    private Employee $employee;

    private AsabUser $accountant;

    private ShiftReportRevisionService $revisionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'شركة الاختبار',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'علامة الاختبار',
            'abbr' => 'TEST',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $template = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'موظف تجريبي',
            'emp_number' => 'EMP-001',
            'role' => 'staff',
            'email' => 'emp1@test.com',
            'status' => 'active',
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'المحاسب',
            'email' => 'accountant@test.com',
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

        $this->cashierShift = CashierShift::factory()->create([
            'shift_id' => $template->id,
            'cashier_id' => $this->cashier->id,
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '40.00',
            'cash_collected' => '60.00',
            'closing_balance' => '60.00',
            'variance' => '0.00',
            'status' => 'in_progress',
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 6000,
            'expected_halalas' => 6000,
            'variance_halalas' => 0,
        ]);
    }

    public function test_post_accountant_correction_successful_and_idempotent(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Audit adjustment on card receipts from pos statement',
            'changes' => [
                'card_payments' => '30.00',
            ],
            'shortage_allocations' => [
                [
                    'type' => 'cashier',
                    'id' => $this->cashier->id,
                    'amount' => 1000,
                ],
            ],
        ];

        $res1 = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res1->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('manager_review_required', false)
            ->assertJsonPath('financial_results.card_payments', '30.00')
            ->assertJsonPath('financial_results.variance', '-10.00')
            ->assertJsonPath('financial_results.variance_halalas', -1000)
            ->assertJsonPath('financial_results.actual_count_halalas', 6000);

        // Replay exact same key + payload returns stored snapshot
        $res2 = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res2->assertOk();
        $this->assertSame($res1->getContent(), $res2->getContent());
        $aggregate = ShiftReportAggregate::where('source_type', 'cashier_shift')->where('source_id', $this->cashierShift->id)->firstOrFail();
        $this->assertSame(2, ShiftReportRevision::where('report_aggregate_id', $aggregate->id)->count());
    }

    public function test_idempotency_key_reused_with_different_payload_returns_409(): void
    {
        $key = (string) Str::uuid();
        $payload1 = [
            'expected_revision' => 1,
            'reason' => 'Initial card adjustment',
            'changes' => ['card_payments' => '30.00'],
            'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000]],
        ];
        $payload2 = [
            'expected_revision' => 1,
            'reason' => 'Different payload with same key',
            'changes' => ['card_payments' => '35.00'],
            'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500]],
        ];

        $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload1)
            ->assertOk();

        $retry = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload2);

        $retry->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_stale_expected_revision_returns_409_without_mutation(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 99,
            'reason' => 'Stale adjustment',
            'changes' => ['card_payments' => '30.00'],
        ];

        $res = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res->assertStatus(409)
            ->assertJsonPath('error.code', 'STALE_REPORT_REVISION');

        $aggregate = ShiftReportAggregate::where('source_type', 'cashier_shift')->where('source_id', $this->cashierShift->id)->firstOrFail();
        $this->assertSame(1, ShiftReportRevision::where('report_aggregate_id', $aggregate->id)->count());
    }

    public function test_modifying_gross_sales_prohibited_returns_422(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Trying to tamper total sales',
            'changes' => ['total_sales' => '200.00'],
        ];

        $res = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'GROSS_SALES_MODIFICATION_PROHIBITED');
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Missing idempotency key',
            'changes' => ['card_payments' => '30.00'],
        ];

        $res = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_company_me_alias_route_works_with_idempotency(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Company alias adjustment',
            'changes' => ['card_payments' => '30.00'],
            'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000]],
        ];

        $res = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('manager_review_required', false);
    }

    public function test_unauthorized_accountant_foreign_company_returns_403(): void
    {
        $foreignCompany = AsabCompany::create(['name' => 'Foreign Co', 'plan' => 'Professional', 'status' => 'active']);
        $foreignAccountant = AsabUser::create([
            'company_id' => $foreignCompany->id,
            'name' => 'محاسب أجنبي',
            'email' => 'foreign@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $foreignAccountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $foreignCompany->id,
            'user_id' => $foreignAccountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Cross-tenant attempt',
            'changes' => ['card_payments' => '30.00'],
        ];

        $res = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($foreignAccountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShift->id}/corrections", $payload);

        $res->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_admin_shift_uuid_resolves_to_cashier_shift(): void
    {
        $adminShift = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_employee_id' => $this->employee->id,
            'shift_type' => 'صباحي',
            'role' => 'كاشير',
            'status' => 'pending_review',
            'legacy_shift_id' => $this->cashierShift->id,
            'sales_amount' => 10000,
            'variance' => 0,
        ]);

        $key = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Mapped via admin shift id',
            'changes' => ['card_payments' => '30.00'],
            'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000]],
        ];

        $res = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$adminShift->id}/corrections", $payload);

        $res->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('financial_results.card_payments', '30.00');
    }
}
