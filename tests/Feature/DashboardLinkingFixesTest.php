<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Support\ModuleCatalog;
use Tests\TestCase;

/**
 * Dashboard bug-fixes from the client meeting 2026-07-22: the read side derived
 * accountant coverage from the (empty) restaurant_ids of brand-scoped accountants,
 * so restaurants/accountant-counts showed "zero", and brand/restaurant ids were
 * returned without names. See docs/tasks/dashboard-mobile-linking-FRD.md.
 */
class DashboardLinkingFixesTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurantA;

    private AsabRestaurant $restaurantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin', 'admin@link.test', ['scope' => 'all']);
        $this->company = AsabCompany::create(['name' => 'Link Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Bazooka', 'abbr' => 'BZ',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => ['sales', 'expenses'],
        ]);
        $this->restaurantA = $this->restaurant('Bazooka Riyadh');
        $this->restaurantB = $this->restaurant('Bazooka Jeddah');
    }

    private function makeUser(string $role, string $email, array $roleAttrs = []): AsabUser
    {
        $user = AsabUser::create([
            'name' => ucfirst($role).' User', 'email' => $email,
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(array_merge(['user_id' => $user->id, 'role_key' => $role], $roleAttrs));

        return $user;
    }

    private function restaurant(string $name): AsabRestaurant
    {
        return AsabRestaurant::create([
            'brand_id' => $this->brand->id, 'company_id' => $this->company->id,
            'name' => $name, 'city' => 'Riyadh', 'status' => 'active',
            // Deliberately stale: the column is never maintained for brand-scoped
            // accountants; the API must derive the real count, not read this.
            'accountant_count' => 0,
        ]);
    }

    /** An accountant assigned to the brand (brand-level scope, empty restaurant_ids). */
    private function brandAccountant(string $email = 'acc@link.test'): AsabUser
    {
        return $this->makeUser('accountant', $email, [
            'scope' => 'brand', 'brand_ids' => [$this->brand->id], 'restaurant_ids' => [],
        ]);
    }

    // ---- BUG-2: restaurant accountant count derived from brand-scoped accountants ----

    public function test_brand_tree_reports_real_accountant_count_for_brand_scoped_accountant(): void
    {
        $this->brandAccountant('acc1@link.test');
        $this->brandAccountant('acc2@link.test');

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/brands');

        $res->assertStatus(200);
        $restaurants = collect($res->json('data.0.restaurants'));
        $this->assertCount(2, $restaurants);
        // Both restaurants belong to the brand → both covered by the 2 accountants.
        $restaurants->each(fn ($r) => $this->assertSame(2, $r['accountants']));
    }

    public function test_restaurant_with_no_brand_accountant_reports_zero(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/brands');

        $res->assertStatus(200)->assertJsonPath('data.0.restaurants.0.accountants', 0);
    }

    // ---- BUG-4: distribution derives restaurants through brands, with names ----

    public function test_distribution_returns_brand_derived_restaurants_with_names(): void
    {
        $acc = $this->brandAccountant();

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/distribution');

        $res->assertStatus(200);

        $accountant = collect($res->json('accountants'))->firstWhere('id', $acc->id);
        $this->assertNotNull($accountant);
        // Covered restaurants come from the brand, not the empty restaurant_ids.
        $this->assertEqualsCanonicalizing(
            [$this->restaurantA->id, $this->restaurantB->id],
            $accountant['restaurants']
        );

        $names = collect($accountant['restaurantsNamed'])->pluck('name')->all();
        $this->assertContains('Bazooka Riyadh', $names);

        // id => name map + assigned/free reflect the derived coverage.
        $this->assertSame('Bazooka Riyadh', $res->json("restaurantNames.{$this->restaurantA->id}"));
        $this->assertContains($this->restaurantA->id, $res->json('assignedRestaurants'));
        $this->assertNotContains($this->restaurantA->id, $res->json('freeRestaurants'));
    }

    public function test_distribution_free_restaurants_when_no_accountant(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/distribution');

        $res->assertStatus(200);
        $this->assertContains($this->restaurantA->id, $res->json('freeRestaurants'));
        $this->assertSame([], $res->json('assignedRestaurants'));
    }

    // ---- BUG-3: users list resolves brand/restaurant/branch names ----

    public function test_users_list_returns_brand_names_alongside_ids(): void
    {
        $acc = $this->brandAccountant('named-acc@link.test');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=named-acc@link.test');

        $res->assertStatus(200)
            // ids unchanged (contract) ...
            ->assertJsonPath('data.0.brands', [$this->brand->id])
            // ... names added.
            ->assertJsonPath('data.0.brandsNamed.0.id', $this->brand->id)
            ->assertJsonPath('data.0.brandsNamed.0.name', 'Bazooka');
    }

    // ---- BUG-1: brand module widget count ----

    public function test_brand_module_count_uses_explicit_subset(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/brands');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.modules', ['sales', 'expenses'])
            ->assertJsonPath('data.0.moduleCount', 2);
    }

    public function test_brand_with_no_modules_reports_full_catalog_count(): void
    {
        AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Aardvark Brand', 'abbr' => 'AB',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => [],
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/brands');

        $res->assertStatus(200);
        // Ordered by name → "Aardvark" is first.
        $res->assertJsonPath('data.0.name', 'Aardvark Brand')
            ->assertJsonPath('data.0.moduleCount', count(ModuleCatalog::keys()));
    }
}
