<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Services\ManagerRosterService;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * «كشف حساب الموظفين: الفلترة بالفرع بتُسقط مدير الفرع» (2026-08-10).
 *
 * `GET /company/me/employees?branchId=X` filters `asab_employees.branch_id`.
 * A branch manager's roster row was created once and never moved, so after a
 * transfer they were missing from the branch they actually run — while still
 * showing on the unfiltered list.
 */
class EmployeeBranchFilterManagerTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Roster Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branchA = Branch::factory()->create([
            'name' => 'فرع أ', 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);
        $this->branchB = Branch::factory()->create([
            'name' => 'فرع ب', 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);

        $this->accountant = $this->user('acc@roster.test', 'accountant', 'all');
        $this->manager = $this->user('mgr@roster.test', 'branch', 'branch', [$this->branchA->id]);
        $this->manager->forceFill(['name' => 'الحسن يوسف'])->save();

        $this->branchA->forceFill(['asab_manager_user_id' => $this->manager->id])->save();
    }

    private function user(string $email, string $role, string $scope, array $branchIds = []): AsabUser
    {
        $u = AsabUser::create([
            'company_id' => $this->company->id, 'name' => $role, 'email' => $email,
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => $scope, 'branch_ids' => $branchIds]);

        return $u;
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    private function roster(): ManagerRosterService
    {
        return app(ManagerRosterService::class);
    }

    /** @return string[] employee ids in the branch-filtered list */
    private function listFor(Branch $branch): array
    {
        return collect($this->acc()->getJson("/api/v1/company/me/employees?branchId={$branch->id}")
            ->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_branch_filter_returns_the_branch_manager_alongside_staff(): void
    {
        $cashier = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchA->id,
            'emp_number' => 'EMP-0001', 'name' => 'كاشير', 'role' => 'كاشير',
            'monthly_salary' => 300000, 'status' => 'active',
        ]);
        $managerRow = $this->roster()->sync($this->manager->id, $this->branchA->id);

        $this->assertNotNull($managerRow);
        $this->assertEqualsCanonicalizing(
            [$cashier->id, $managerRow->id],
            $this->listFor($this->branchA),
        );
    }

    public function test_a_manager_whose_row_is_stranded_on_the_previous_branch_still_lists_on_the_new_one(): void
    {
        // The production shape: a row created by an old `asab:repair-employees`
        // run, on branch B, with no dashboard stamp at all.
        $stranded = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchB->id,
            'emp_number' => 'EMP-0009', 'name' => 'الحسن يوسف', 'role' => ManagerRosterService::MANAGER_ROLE,
            'monthly_salary' => 0, 'status' => 'active',
        ]);

        $rows = collect($this->acc()->getJson("/api/v1/company/me/employees?branchId={$this->branchA->id}")
            ->assertOk()->json('data'));

        $this->assertContains($stranded->id, $rows->pluck('id')->all());
        // …and it reports the branch it manages, so the dropdown and the row agree.
        $this->assertSame($this->branchA->id, $rows->firstWhere('id', $stranded->id)['branchId'] ?? null);
        $this->assertSame('فرع أ', $rows->firstWhere('id', $stranded->id)['branchName'] ?? null);
    }

    public function test_sync_moves_the_existing_row_instead_of_creating_a_duplicate(): void
    {
        $original = $this->roster()->sync($this->manager->id, $this->branchA->id);

        $this->branchA->forceFill(['asab_manager_user_id' => null])->save();
        $this->branchB->forceFill(['asab_manager_user_id' => $this->manager->id])->save();
        $moved = $this->roster()->sync($this->manager->id, $this->branchB->id);

        $this->assertSame($original->id, $moved->id);
        $this->assertSame($this->branchB->id, $moved->branch_id);
        $this->assertSame($this->manager->id, $moved->asab_user_id);
        $this->assertSame(1, Employee::withoutGlobalScopes()
            ->where('name', 'الحسن يوسف')->whereNull('deleted_at')->count());

        $this->assertContains($moved->id, $this->listFor($this->branchB));
        $this->assertNotContains($moved->id, $this->listFor($this->branchA));
    }

    public function test_transfer_manager_moves_the_roster_row_with_the_branch(): void
    {
        $row = $this->roster()->sync($this->manager->id, $this->branchA->id);

        $newManager = $this->user('mgr2@roster.test', 'branch', 'branch', [$this->branchB->id]);
        $newManager->forceFill(['name' => 'مدير جديد'])->save();
        CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $newManager->id,
            'role_key' => 'branch', 'status' => 'active',
        ]);
        AsabRestaurant::create(['company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم']);

        $companyAdmin = $this->user('ca@roster.test', 'company-admin', 'all');
        $this->actingAs($companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/branches/{$this->branchA->id}/transfer-manager", [
                'newManagerUserId' => $newManager->id,
            ])->assertOk();

        // The incoming manager is now on branch A's roster…
        $ids = $this->listFor($this->branchA);
        $incoming = Employee::withoutGlobalScopes()->where('asab_user_id', $newManager->id)->first();
        $this->assertNotNull($incoming);
        $this->assertSame($this->branchA->id, $incoming->branch_id);
        $this->assertContains($incoming->id, $ids);

        // …and the outgoing one's row is untouched (their ledger stays where it is).
        $this->assertSame($this->branchA->id, $row->fresh()->branch_id);
    }
}
