<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * WS3 — user-assignment business rules from the client meeting:
 * branch manager = exactly ONE branch; accountant = assigned at BRAND level.
 */
class UserAssignmentRulesTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAsabUser('admin', 'admin@rules.test');
        $this->company = AsabCompany::create(['name' => 'Rules Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Brand A', 'abbr' => 'BA',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => ['sales'],
        ]);
        $this->restaurant = AsabRestaurant::create([
            'brand_id' => $this->brand->id, 'company_id' => $this->company->id,
            'name' => 'R1', 'city' => 'Riyadh', 'status' => 'active',
        ]);
    }

    private function makeAsabUser(string $role, string $email, array $roleAttrs = [], ?string $companyId = null): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $companyId,
            'name' => ucfirst($role).' User',
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(array_merge(
            ['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all'],
            $roleAttrs
        ));

        return $user;
    }

    private function branchFixture(array $attrs = []): Branch
    {
        return Branch::factory()->create(array_merge([
            'asab_restaurant_id' => $this->restaurant->id,
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
        ], $attrs));
    }

    private function userPayload(string $role, array $extra = []): array
    {
        return array_merge([
            'name' => 'New User',
            'email' => uniqid('u').'@rules.test',
            'role' => $role,
        ], $extra);
    }

    // ---- Admin user store: per-role rules ----

    public function test_branch_manager_creation_with_zero_branches_is_rejected(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('branch'));

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_branch_manager_creation_with_two_branches_is_rejected(): void
    {
        $b1 = $this->branchFixture();
        $b2 = $this->branchFixture();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('branch', ['branches' => [$b1->id, $b2->id]]));

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_branch_manager_creation_with_one_branch_forces_branch_scope(): void
    {
        $b1 = $this->branchFixture();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('branch', [
                // Required in practice: BranchManagerProvisioner fails closed
                // (BRANCH_NOT_IN_COMPANY) when the user has no company to own
                // the branch, even though the rule says companyId is nullable.
                'companyId' => $this->company->id,
                'branches' => [$b1->id],
                'scope' => 'all', // must be overridden
            ]));

        $res->assertStatus(201)
            ->assertJsonPath('scope', 'branch')
            ->assertJsonPath('branches', [$b1->id]);
    }

    public function test_branch_manager_creation_without_a_company_is_rejected(): void
    {
        $b1 = $this->branchFixture();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('branch', ['branches' => [$b1->id]]));

        $res->assertStatus(422)->assertJsonPath('error.code', 'BRANCH_NOT_IN_COMPANY');
    }

    public function test_branch_manager_creation_rejects_a_branch_from_another_company(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $b1 = $this->branchFixture();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('branch', [
                'companyId' => $otherCompany->id,
                'branches' => [$b1->id],
            ]));

        $res->assertStatus(422)->assertJsonPath('error.code', 'BRANCH_NOT_IN_COMPANY');
    }

    public function test_accountant_creation_without_brands_is_rejected(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('accountant'));

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_accountant_creation_gets_brand_scope_and_ignores_branch_ids(): void
    {
        $b1 = $this->branchFixture();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('accountant', [
                'brands' => [$this->brand->id],
                'branches' => [$b1->id],
            ]));

        $res->assertStatus(201)
            ->assertJsonPath('scope', 'brand')
            ->assertJsonPath('brands', [$this->brand->id])
            ->assertJsonPath('branches', [])
            ->assertJsonPath('restaurants', []);

        $assignment = AsabUserRole::where('user_id', $res->json('id'))->firstOrFail();
        $this->assertSame('brand', $assignment->scope);
        $this->assertSame([$this->brand->id], $assignment->brand_ids);
    }

    /**
     * The scope was always forced to brand; the restaurants array was accepted
     * and dropped, so the 201 echoed "restaurants": [] and read as saved.
     */
    public function test_accountant_creation_with_restaurants_is_rejected(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('accountant', [
                'brands' => [$this->brand->id],
                'restaurants' => [$this->restaurant->id],
            ]));

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['restaurants']]]);
    }

    public function test_accountant_creation_with_non_brand_scope_is_rejected(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('accountant', [
                'brands' => [$this->brand->id],
                'scope' => 'restaurant',
            ]));

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /** `prohibited` permits an empty value — the FE sends [] for unused pickers. */
    public function test_accountant_creation_tolerates_empty_restaurants_array(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('accountant', [
                'brands' => [$this->brand->id],
                'restaurants' => [],
            ]));

        $res->assertStatus(201)->assertJsonPath('scope', 'brand');
    }

    public function test_update_accountant_with_restaurants_is_rejected(): void
    {
        $acc = $this->makeAsabUser('accountant', 'acc-rest@rules.test', [
            'scope' => 'brand', 'brand_ids' => [$this->brand->id],
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$acc->id}", ['restaurants' => [$this->restaurant->id]]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_branch_manager_creation_ignores_brand_and_restaurant_ids(): void
    {
        $b1 = $this->branchFixture();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('branch', [
                'companyId' => $this->company->id,
                'branches' => [$b1->id],
                'brands' => [$this->brand->id],
                'restaurants' => [$this->restaurant->id],
            ]));

        $res->assertStatus(201)
            ->assertJsonPath('scope', 'branch')
            ->assertJsonPath('branches', [$b1->id])
            ->assertJsonPath('brands', [])
            ->assertJsonPath('restaurants', []);

        $assignment = AsabUserRole::where('user_id', $res->json('id'))->firstOrFail();
        $this->assertSame([], $assignment->brand_ids);
        $this->assertSame([], $assignment->restaurant_ids);
    }

    // ---- reportsTo: uuid of an existing user, never a display name ----

    /**
     * reports_to_id is a uuid column with no FK. A display name used to be
     * written straight through, leaving an unresolvable head reference.
     */
    public function test_reports_to_rejects_a_display_name(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('head', ['reportsTo' => 'Mohammed Ali']));

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['reportsTo']]]);
    }

    public function test_reports_to_rejects_unknown_user_id(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('head', [
                'reportsTo' => '00000000-0000-4000-8000-000000000000',
            ]));

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_reports_to_accepts_head_id_and_is_returned_with_name(): void
    {
        $head = $this->makeAsabUser('head', 'head-rt@rules.test');

        $create = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('accountant', [
                'brands' => [$this->brand->id],
                'reportsTo' => $head->id,
            ]));

        $create->assertStatus(201)
            ->assertJsonPath('reportsTo.id', $head->id)
            ->assertJsonPath('reportsTo.name', $head->name);

        $index = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=head-rt@rules.test');
        $index->assertStatus(200)->assertJsonPath('data.0.reportsTo', null);
    }

    public function test_admin_creation_keeps_default_all_scope(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->userPayload('admin'));

        $res->assertStatus(201)->assertJsonPath('scope', 'all');
    }

    // ---- Admin user update: assignment editing ----

    public function test_update_branch_manager_with_two_branches_is_rejected(): void
    {
        $b1 = $this->branchFixture();
        $b2 = $this->branchFixture();
        $manager = $this->makeAsabUser('branch', 'bm@rules.test', ['scope' => 'branch', 'branch_ids' => [$b1->id]]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$manager->id}", ['branches' => [$b1->id, $b2->id]]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_update_reassigns_branch_manager_to_one_branch(): void
    {
        $b1 = $this->branchFixture();
        $b2 = $this->branchFixture();
        $manager = $this->makeAsabUser('branch', 'bm2@rules.test', [
            'scope' => 'branch', 'branch_ids' => [$b1->id],
            'brand_ids' => [$this->brand->id], 'restaurant_ids' => [$this->restaurant->id], // stale
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$manager->id}", ['branches' => [$b2->id]]);

        $res->assertStatus(200)
            ->assertJsonPath('scope', 'branch')
            ->assertJsonPath('branches', [$b2->id]);

        // Stale brand/restaurant ids must be cleared, or the resolver ORs them in.
        $assignment = AsabUserRole::where('user_id', $manager->id)->firstOrFail();
        $this->assertSame([], $assignment->brand_ids);
        $this->assertSame([], $assignment->restaurant_ids);
    }

    public function test_update_accountant_brands_syncs_brand_scope(): void
    {
        $b1 = $this->branchFixture();
        $acc = $this->makeAsabUser('accountant', 'acc-upd@rules.test', [
            'scope' => 'restaurant', 'restaurant_ids' => [$this->restaurant->id], 'branch_ids' => [$b1->id],
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$acc->id}", ['brands' => [$this->brand->id]]);

        $res->assertStatus(200)
            ->assertJsonPath('scope', 'brand')
            ->assertJsonPath('brands', [$this->brand->id])
            ->assertJsonPath('restaurants', [])
            ->assertJsonPath('branches', []);

        // Stale restaurant/branch ids cleared — otherwise the resolver keeps
        // granting the old restaurants (cross-brand escape).
        $assignment = AsabUserRole::where('user_id', $acc->id)->firstOrFail();
        $this->assertSame([], $assignment->restaurant_ids);
        $this->assertSame([], $assignment->branch_ids);

        $res2 = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$acc->id}", ['brands' => []]);

        $res2->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ---- Distribution: brands payload -> brand scope ----

    public function test_distribution_assignments_brands_payload_writes_brand_scope(): void
    {
        $acc = $this->makeAsabUser('accountant', 'acc-dist@rules.test', ['scope' => 'restaurant', 'restaurant_ids' => [$this->restaurant->id]]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/accountants/{$acc->id}/assignments", ['brands' => [$this->brand->id]]);

        $res->assertStatus(200)
            ->assertJsonPath('scope', 'brand')
            ->assertJsonPath('brands', [$this->brand->id]);

        $assignment = AsabUserRole::where('user_id', $acc->id)->where('role_key', 'accountant')->firstOrFail();
        $this->assertSame('brand', $assignment->scope);
        $this->assertSame([$this->brand->id], $assignment->brand_ids);
        $this->assertSame([], $assignment->restaurant_ids); // stale ids cleared
    }

    public function test_distribution_assignments_restaurants_only_keeps_restaurant_scope(): void
    {
        $acc = $this->makeAsabUser('accountant', 'acc-dist2@rules.test');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/accountants/{$acc->id}/assignments", ['restaurants' => [$this->restaurant->id]]);

        $res->assertStatus(200)
            ->assertJsonPath('scope', 'restaurant')
            ->assertJsonPath('restaurants', [$this->restaurant->id]);
    }

    // ---- Company members: brand change syncs asab_user_roles ----

    public function test_company_member_brand_change_syncs_asab_user_roles(): void
    {
        $brand2 = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Brand B', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => ['sales'],
        ]);
        $coAdmin = $this->makeAsabUser('company-admin', 'co-admin@rules.test', [], $this->company->id);
        $member = $this->makeAsabUser('accountant', 'member@rules.test', [
            'scope' => 'brand', 'brand_ids' => [$this->brand->id],
            'restaurant_ids' => [$this->restaurant->id], // stale, must be cleared on sync
        ], $this->company->id);
        $cu = CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $member->id,
            'role_key' => 'accountant', 'brand_id' => $this->brand->id, 'status' => 'active',
        ]);

        $res = $this->actingAs($coAdmin, 'sanctum')
            ->patchJson("/api/v1/company/me/users/{$cu->id}", ['brandId' => $brand2->id]);

        $res->assertStatus(200);
        $this->assertSame($brand2->id, $cu->fresh()->brand_id);

        $assignment = AsabUserRole::where('user_id', $member->id)->where('role_key', 'accountant')->firstOrFail();
        $this->assertSame('brand', $assignment->scope);
        $this->assertSame([$brand2->id], $assignment->brand_ids);
        $this->assertSame([], $assignment->restaurant_ids);
        $this->assertSame([], $assignment->branch_ids);
    }

    public function test_company_accountant_invite_requires_brand(): void
    {
        $coAdmin = $this->makeAsabUser('company-admin', 'co-admin2@rules.test', [], $this->company->id);

        $res = $this->actingAs($coAdmin, 'sanctum')
            ->postJson('/api/v1/company/me/users', [
                'email' => 'invitee@rules.test',
                'roleKey' => 'accountant',
            ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ROLE_SCOPE');
    }

    // ---- Branch manager exclusivity ----

    public function test_second_branch_managed_by_same_user_is_rejected(): void
    {
        $manager = $this->makeAsabUser('branch', 'bm-x@rules.test', ['scope' => 'branch']);
        $this->branchFixture(['asab_manager_user_id' => $manager->id]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/restaurants/{$this->restaurant->id}/branches", [
                'name' => 'Second Branch',
                'managerUserId' => $manager->id,
            ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'MANAGER_ALREADY_ASSIGNED');
    }

    public function test_branch_update_rejects_manager_of_another_branch_but_allows_self(): void
    {
        $manager = $this->makeAsabUser('branch', 'bm-y@rules.test', ['scope' => 'branch']);
        $managed = $this->branchFixture(['asab_manager_user_id' => $manager->id]);
        $other = $this->branchFixture();

        $conflict = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/branches/{$other->id}", ['managerUserId' => $manager->id]);
        $conflict->assertStatus(422)->assertJsonPath('error.code', 'MANAGER_ALREADY_ASSIGNED');

        $self = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/branches/{$managed->id}", ['managerUserId' => $manager->id]);
        $self->assertStatus(200);
    }

    public function test_branch_manager_assignment_requires_branch_role(): void
    {
        $notManager = $this->makeAsabUser('accountant', 'acc-nm@rules.test', ['scope' => 'brand', 'brand_ids' => [$this->brand->id]]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/restaurants/{$this->restaurant->id}/branches", [
                'name' => 'Branch Z',
                'managerUserId' => $notManager->id,
            ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'MANAGER_ROLE_INVALID');
    }
}
