<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\IdentityMapService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Tests\TestCase;

/**
 * Meeting 2026-08-03 «تم إضافة فرع جديد ولكن يوجد به بيانات قديمة من تاريخ
 * سابق»: the dashboard wrote `branches.asab_manager_user_id` and the ASAB role
 * scope when a manager was assigned, and nothing else. The app scopes every
 * screen by `branch_managers.branch_id`, which only account creation ever set —
 * so the manager kept opening their PREVIOUS branch: its inventory sessions,
 * its waste reports, its assets.
 */
class ManagerBranchSyncTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabRestaurant $restaurant;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Brand', 'abbr' => 'BR',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $brand->id, 'name' => 'Rest', 'status' => 'active',
        ]);

        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@sync.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
    }

    /** A dashboard manager user linked to an existing mobile login. */
    private function linkedManager(Branch $oldBranch): array
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير', 'email' => 'manager@sync.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$oldBranch->id],
        ]);
        $manager = BranchManager::factory()->create([
            'branch_id' => $oldBranch->id, 'email' => 'manager@sync.test',
        ]);
        app(IdentityMapService::class)->linkBranchManager(
            $user->id, $manager->id, $this->company->id, $user->email,
        );

        return [$user, $manager];
    }

    public function test_creating_a_branch_with_a_manager_moves_their_mobile_login(): void
    {
        $oldBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        [$user, $manager] = $this->linkedManager($oldBranch);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/restaurants/{$this->restaurant->id}/branches", [
                'name' => 'الريان 1',
                'managerUserId' => $user->id,
            ])
            ->assertStatus(201);

        $newBranch = Branch::where('name', 'الريان 1')->firstOrFail();
        $this->assertSame($newBranch->id, $manager->fresh()->branch_id);
    }

    public function test_reassigning_a_manager_on_a_branch_moves_their_mobile_login(): void
    {
        $oldBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        [$user, $manager] = $this->linkedManager($oldBranch);
        $newBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/branches/{$newBranch->id}", ['managerUserId' => $user->id])
            ->assertStatus(200);

        $this->assertSame($newBranch->id, $manager->fresh()->branch_id);
    }

    public function test_reassigning_into_an_occupied_branch_rolls_back_dashboard_and_mobile_assignment(): void
    {
        $oldBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        [$user, $manager] = $this->linkedManager($oldBranch);
        $occupiedBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        BranchManager::factory()->create(['branch_id' => $occupiedBranch->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/branches/{$occupiedBranch->id}", ['managerUserId' => $user->id])
            ->assertStatus(422);

        $this->assertNull($occupiedBranch->fresh()->asab_manager_user_id);
        $this->assertSame($oldBranch->id, $manager->fresh()->branch_id);
    }

    public function test_the_repair_command_repoints_mismatched_logins(): void
    {
        $oldBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        [$user, $manager] = $this->linkedManager($oldBranch);

        // The pre-fix production state: dashboard says branch B, mobile says A.
        $newBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id, 'asab_manager_user_id' => $user->id,
        ]);

        $this->artisan('asab:sync-manager-branches --dry-run')->assertSuccessful();
        $this->assertSame($oldBranch->id, $manager->fresh()->branch_id, 'dry run must not write');

        $this->artisan('asab:sync-manager-branches')->assertSuccessful();
        $this->assertSame($newBranch->id, $manager->fresh()->branch_id);
    }
}
