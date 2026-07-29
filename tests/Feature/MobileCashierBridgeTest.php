<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierService;
use Tests\TestCase;

/**
 * Cashier ownership after the direction flip: cashier accounts are created in
 * the MOBILE app by the branch manager, never on the dashboard. The dashboard
 * refuses cashier-role employee writes and instead shows the mobile-created
 * cashiers of the branch, mirrored into asab_employees by the reverse bridge.
 */
class MobileCashierBridgeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    private Branch $branch;

    private BranchManager $legacyManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->legacyManager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير الفرع',
            'email' => 'branch-manager@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->manager->id,
            'role_key' => 'branch',
            'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);
    }

    private function asManager()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    /** Create a cashier the way the mobile app does (event → mirror listener). */
    private function mobileCashier(string $name = 'سارة الكاشير', string $email = 'sara.cashier@asab.test'): Cashier
    {
        return app(CashierService::class)->createCashier([
            'name' => $name,
            'email' => $email,
            'branch_id' => $this->branch->id,
            'created_by' => $this->legacyManager->id,
        ]);
    }

    // ---- The dashboard no longer creates cashiers ----

    public function test_company_endpoint_refuses_a_cashier_role_employee(): void
    {
        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'سارة الكاشير',
            'role' => 'Cashier',
            'salaryHalalas' => 450000,
        ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'CASHIER_MOBILE_ONLY');
        $this->assertSame(0, Employee::withoutGlobalScopes()->count());
        $this->assertSame(0, Cashier::count());
    }

    public function test_arabic_cashier_role_is_refused_on_the_branch_endpoint_too(): void
    {
        $res = $this->asManager()->postJson('/api/v1/branch/employees', [
            'empNumber' => 'EMP-0007',
            'name' => 'أحمد',
            'role' => 'كاشير',
            'monthlySalary' => 400000,
        ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'CASHIER_MOBILE_ONLY');
        $this->assertSame(0, Employee::withoutGlobalScopes()->count());
    }

    public function test_non_cashier_role_is_still_created(): void
    {
        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'طباخ',
            'role' => 'Chef',
            'salaryHalalas' => 500000,
        ]);

        $res->assertCreated();
        $this->assertSame(1, Employee::count());
        $this->assertSame(0, Cashier::count());
    }

    // ---- Mobile → dashboard mirror ----

    public function test_mobile_cashier_is_mirrored_into_asab_employees_and_linked(): void
    {
        $cashier = $this->mobileCashier();

        $employee = Employee::withoutGlobalScopes()->where('legacy_cashier_id', $cashier->id)->first();
        $this->assertNotNull($employee);
        $this->assertSame($this->company->id, $employee->company_id);
        $this->assertSame($this->branch->id, $employee->branch_id);
        $this->assertSame('cashier', $employee->role);
        $this->assertSame(0, $employee->monthly_salary); // the accountant fills payroll in

        $this->assertDatabaseHas('asab_identity_map', [
            'entity_type' => AsabIdentityMap::ENTITY_CASHIER,
            'dashboard_id' => $employee->id,
            'legacy_id' => $cashier->id,
        ]);
    }

    public function test_mirroring_is_idempotent(): void
    {
        $cashier = $this->mobileCashier();
        app(\Modules\Admin\Services\MobileCashierMirrorService::class)->mirror($cashier);

        $this->assertSame(1, Employee::withoutGlobalScopes()->where('legacy_cashier_id', $cashier->id)->count());
    }

    // ---- The dashboard branch roster shows them ----

    public function test_branch_employees_lists_the_mobile_cashier_as_mobile_sourced(): void
    {
        $cashier = $this->mobileCashier();

        $res = $this->asManager()->getJson('/api/v1/company/me/branch/employees');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('name', $cashier->name);
        $this->assertNotNull($row);
        $this->assertSame('mobile', $row['source']);
        $this->assertSame($this->legacyManager->name, $row['addedBy']);
        $this->assertSame($cashier->email, $row['email']);
    }

    public function test_an_unmirrored_legacy_cashier_is_still_read_through(): void
    {
        // A cashier that predates the mirror (no asab_employees counterpart).
        $cashier = Cashier::create([
            'name' => 'كاشير قديم',
            'email' => 'legacy@asab.test',
            'password' => 'legacy-password',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'created_by' => $this->legacyManager->id,
        ]);

        $res = $this->asManager()->getJson('/api/v1/company/me/branch/employees');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('name', $cashier->name);
        $this->assertNotNull($row);
        $this->assertSame('mobile', $row['source']);
        $this->assertNull($row['empNumber']);
    }

    public function test_a_cashier_of_another_branch_is_not_listed(): void
    {
        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);
        Cashier::create([
            'name' => 'كاشير فرع آخر',
            'email' => 'other-branch@asab.test',
            'password' => 'other-password',
            'branch_id' => $otherBranch->id,
            'status' => 'active',
            'created_by' => $otherManager->id,
        ]);

        $res = $this->asManager()->getJson('/api/v1/company/me/branch/employees');
        $res->assertOk();

        $this->assertNull(collect($res->json('data'))->firstWhere('name', 'كاشير فرع آخر'));
    }
}
