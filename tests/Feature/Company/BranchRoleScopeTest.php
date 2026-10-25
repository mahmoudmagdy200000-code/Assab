<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyInvitation;
use Modules\Admin\Services\Testing\StagingHarnessProvisioner;
use Modules\Branch\Models\Branch;
use Tests\Support\Harness\AssabHarnessFixtureBuilder;
use Tests\TestCase;

/**
 * Task BE-02: Branch Role Scope Alignment (E2 + E3)
 *
 * Requirements:
 * - When accepting an invitation for a branch manager ('branch' role),
 *   the assigned role MUST have: scope='branch', brand_ids=[], restaurant_ids=[], branch_ids=[x].
 * - Even when the invitation record stores brand_id, brand_ids must remain empty for branch managers.
 * - StagingHarnessProvisioner must provision branch managers with brand_ids=[] and restaurant_ids=[].
 * - GET /api/v1/auth/me for branch managers must return brandIds=[] and restaurantIds=[].
 */
final class BranchRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabBrand $brand;
    private AsabRestaurant $restaurant;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->company = AsabCompany::create([
            'name' => 'Al-Taj Food Group',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Burger Al-Taj',
            'abbr' => 'BAT',
            'sub_status' => 'active',
            'status' => 'active',
            'plan' => 'ذهبي',
            'modules' => ['sales', 'expenses', 'shifts'],
        ]);

        $this->restaurant = AsabRestaurant::create([
            'brand_id' => $this->brand->id,
            'company_id' => $this->company->id,
            'name' => 'Olaya Complex',
            'city' => 'Riyadh',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_restaurant_id' => $this->restaurant->id,
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
            'name' => 'Olaya Branch',
        ]);
    }

    public function test_branch_invitation_acceptance_sets_empty_brand_and_restaurant_ids(): void
    {
        $token = 'invitation-branch-manager-token-12345';
        CompanyInvitation::create([
            'company_id' => $this->company->id,
            'email' => 'olaya.manager@altaj.test',
            'name' => 'Tariq Al-Olaya',
            'role_key' => 'branch',
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/company/invitations/accept', [
            'token' => $token,
            'name' => 'Tariq Al-Olaya',
            'password' => 'SecurePass2026!',
        ]);

        $response->assertOk();

        $user = AsabUser::where('email', 'olaya.manager@altaj.test')->firstOrFail();
        $role = AsabUserRole::where('user_id', $user->id)->where('role_key', 'branch')->firstOrFail();

        $this->assertSame('branch', $role->scope);
        $this->assertSame([], $role->brand_ids, 'E2: Branch role must have empty brand_ids even if invitation has brand_id');
        $this->assertSame([], $role->restaurant_ids, 'E2: Branch role must have empty restaurant_ids');
        $this->assertSame([$this->branch->id], $role->branch_ids, 'E2: Branch role must have exact branch_id');
    }

    public function test_staging_harness_provisioner_restricts_branch_manager_scope(): void
    {
        (new \Modules\Admin\Database\Seeders\AsabRolePermissionSeeder())->run();

        $builder = new AssabHarnessFixtureBuilder();
        $fixture = $builder->build();
        $branchUser = $fixture->user('branch_manager');

        $role = AsabUserRole::where('user_id', $branchUser->id)->where('role_key', 'branch')->firstOrFail();

        $this->assertSame('branch', $role->scope);
        $this->assertSame([], $role->brand_ids, 'E3: Fixture branch manager must have empty brand_ids');
        $this->assertSame([], $role->restaurant_ids, 'E3: Fixture branch manager must have empty restaurant_ids');
        $this->assertCount(1, $role->branch_ids, 'E3: Fixture branch manager must have exactly one branch_id');
    }

    public function test_branch_manager_auth_me_returns_empty_brand_and_restaurant_ids_for_dashboard(): void
    {
        (new \Modules\Admin\Database\Seeders\AsabRolePermissionSeeder())->run();

        $builder = new AssabHarnessFixtureBuilder();
        $fixture = $builder->build();
        $branchUser = $fixture->user('branch_manager');

        $response = $this->actingAs($branchUser, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk();
        $roles = $response->json('roles');
        $this->assertIsArray($roles);
        $this->assertNotEmpty($roles);

        $branchRole = collect($roles)->firstWhere('key', 'branch');
        $this->assertNotNull($branchRole);
        $this->assertSame('branch', $branchRole['scope']);
        $this->assertSame([], $branchRole['brandIds'], 'E3: /auth/me must return empty brandIds for branch role');
        $this->assertSame([], $branchRole['restaurantIds'], 'E3: /auth/me must return empty restaurantIds for branch role');
        $this->assertCount(1, $branchRole['branchIds']);
    }
}
