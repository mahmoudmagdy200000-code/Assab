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
 * ADM-3.3 «مصفوفة الموديولات» — per-(accountant, restaurant) module permissions.
 *
 * Before this, the grid wrote ONE flat `asab_user_roles.module_keys`: every
 * restaurant echoed the same list, a change to one row changed all of them, and
 * the read side handed the screen bare uuids (it rendered ids and "0/9").
 */
class AccountantModuleMatrixTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurantA;

    private AsabRestaurant $restaurantB;

    private AsabRestaurant $foreignRestaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin', 'admin@matrix.test', ['scope' => 'all']);
        $this->company = AsabCompany::create(['name' => 'Matrix Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Bazooka', 'abbr' => 'BZ',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => ['sales', 'expenses'],
        ]);
        $other = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Other', 'abbr' => 'OT',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => [],
        ]);

        $this->restaurantA = $this->restaurant('Bazooka Riyadh', $this->brand->id);
        $this->restaurantB = $this->restaurant('Bazooka Jeddah', $this->brand->id);
        $this->foreignRestaurant = $this->restaurant('Other Dammam', $other->id);
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

    private function restaurant(string $name, string $brandId): AsabRestaurant
    {
        return AsabRestaurant::create([
            'brand_id' => $brandId, 'company_id' => $this->company->id,
            'name' => $name, 'city' => 'Riyadh', 'status' => 'active',
        ]);
    }

    /** Brand-level accountant carrying a pre-ADM-3.3 flat module list. */
    private function accountant(array $modules = ['sales', 'expenses']): AsabUser
    {
        return $this->makeUser('accountant', 'acc@matrix.test', [
            'scope' => 'brand', 'brand_ids' => [$this->brand->id],
            'restaurant_ids' => [], 'module_keys' => $modules,
        ]);
    }

    private function assignment(AsabUser $acc): AsabUserRole
    {
        return AsabUserRole::where('user_id', $acc->id)->where('role_key', 'accountant')->firstOrFail();
    }

    // ---- read: names + catalogue, not uuids ----

    public function test_matrix_returns_one_named_row_per_covered_restaurant(): void
    {
        $acc = $this->accountant();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/accountants/{$acc->id}/modules");

        $res->assertStatus(200)
            ->assertJsonPath('accountantName', $acc->name)
            ->assertJsonPath('totals.restaurants', 2)
            ->assertJsonPath('totals.modules', count(ModuleCatalog::keys()))
            ->assertJsonPath('reason', null);

        $rows = collect($res->json('restaurants'))->keyBy('restaurantId');
        $this->assertSame('Bazooka Riyadh', $rows[$this->restaurantA->id]['restaurantName']);
        $this->assertSame('Bazooka', $rows[$this->restaurantA->id]['brandName']);
        // The 9 columns come from the backend catalogue.
        $this->assertCount(count(ModuleCatalog::keys()), $res->json('moduleCatalog'));
        // No cell stored yet → the accountant's existing flat grant is shown,
        // not zero.
        $this->assertEqualsCanonicalizing(['sales', 'expenses'], $rows[$this->restaurantA->id]['modules']);
        $this->assertFalse($rows[$this->restaurantA->id]['isExplicit']);
        // Brand package is advisory, so the screen can grey out the rest.
        $this->assertEqualsCanonicalizing(['sales', 'expenses'], $rows[$this->restaurantA->id]['availableModules']);
        // The foreign brand's restaurant is not in this accountant's grid.
        $this->assertArrayNotHasKey($this->foreignRestaurant->id, $rows->all());
    }

    public function test_matrix_names_the_reason_when_the_accountant_covers_nothing(): void
    {
        $acc = $this->makeUser('accountant', 'noscope@matrix.test', [
            'scope' => 'brand', 'brand_ids' => [], 'restaurant_ids' => [],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/accountants/{$acc->id}/modules")
            ->assertStatus(200)
            ->assertJsonPath('restaurants', [])
            ->assertJsonPath('reason', 'NO_COVERED_RESTAURANTS');
    }

    // ---- write: one cell at a time, and it survives a refresh ----

    public function test_setting_modules_for_one_restaurant_leaves_the_other_untouched(): void
    {
        $acc = $this->accountant();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/restaurants/{$this->restaurantA->id}/modules", [
                'modules' => ['sales', 'waste'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('restaurantName', 'Bazooka Riyadh')
            ->assertJsonPath('moduleCount', 2);

        // Re-read (the "refresh" the client reported losing).
        $rows = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/accountants/{$acc->id}/modules")->json('restaurants'))
            ->keyBy('restaurantId');

        $this->assertEqualsCanonicalizing(['sales', 'waste'], $rows[$this->restaurantA->id]['modules']);
        // Restaurant B keeps its own (legacy-seeded) list — the whole point.
        $this->assertEqualsCanonicalizing(['sales', 'expenses'], $rows[$this->restaurantB->id]['modules']);
        $this->assertTrue($rows[$this->restaurantB->id]['isExplicit']);
    }

    public function test_role_module_keys_mirror_the_union_of_the_cells(): void
    {
        $acc = $this->accountant();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/restaurants/{$this->restaurantA->id}/modules", [
                'modules' => ['sales', 'waste'],
            ])->assertStatus(200);

        // auth/tenant resolution reads this one flat array — it must equal the
        // union of the grid (A: sales+waste, B: legacy sales+expenses).
        $this->assertEqualsCanonicalizing(
            ['sales', 'expenses', 'waste'],
            $this->assignment($acc)->fresh()->module_keys,
        );
    }

    public function test_clearing_every_cell_revokes_the_flat_grant(): void
    {
        $acc = $this->accountant();

        foreach ([$this->restaurantA, $this->restaurantB] as $restaurant) {
            $this->actingAs($this->admin, 'sanctum')
                ->putJson("/api/v1/admin/accountants/{$acc->id}/restaurants/{$restaurant->id}/modules", ['modules' => []])
                ->assertStatus(200);
        }

        $this->assertSame([], $this->assignment($acc)->fresh()->module_keys);
    }

    public function test_bulk_save_writes_every_row(): void
    {
        $acc = $this->accountant();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/modules", ['restaurants' => [
                ['restaurantId' => $this->restaurantA->id, 'modules' => ['sales']],
                ['restaurantId' => $this->restaurantB->id, 'modules' => ['inventory', 'cash']],
            ]]);

        $res->assertStatus(200);
        $rows = collect($res->json('restaurants'))->keyBy('restaurantId');
        $this->assertSame(['sales'], $rows[$this->restaurantA->id]['modules']);
        $this->assertEqualsCanonicalizing(['inventory', 'cash'], $rows[$this->restaurantB->id]['modules']);
        $this->assertEqualsCanonicalizing(['sales', 'inventory', 'cash'], $this->assignment($acc)->fresh()->module_keys);
    }

    // ---- zero-trust: a permissions save must not widen data scope ----

    public function test_restaurant_outside_coverage_is_refused_and_scope_is_not_widened(): void
    {
        $acc = $this->accountant();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/restaurants/{$this->foreignRestaurant->id}/modules", [
                'modules' => ['sales'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RESTAURANT_NOT_ASSIGNED');

        // The old endpoint self-assigned the restaurant and flipped the scope.
        $assignment = $this->assignment($acc)->fresh();
        $this->assertSame('brand', $assignment->scope);
        $this->assertSame([], $assignment->restaurant_ids);
    }

    public function test_bulk_save_with_one_foreign_restaurant_writes_nothing(): void
    {
        $acc = $this->accountant();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/modules", ['restaurants' => [
                ['restaurantId' => $this->restaurantA->id, 'modules' => ['waste']],
                ['restaurantId' => $this->foreignRestaurant->id, 'modules' => ['sales']],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RESTAURANT_NOT_ASSIGNED');

        // Refused as a whole: restaurant A must still be on its legacy list.
        $rows = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/accountants/{$acc->id}/modules")->json('restaurants'))
            ->keyBy('restaurantId');
        $this->assertEqualsCanonicalizing(['sales', 'expenses'], $rows[$this->restaurantA->id]['modules']);
    }

    public function test_reassigning_the_brand_stops_the_old_cells_from_granting(): void
    {
        $acc = $this->accountant();
        $emptyBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'Empty', 'abbr' => 'EM',
            'sub_status' => 'active', 'status' => 'active', 'plan' => 'ذهبي', 'modules' => [],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/restaurants/{$this->restaurantA->id}/modules", [
                'modules' => ['sales', 'waste'],
            ])->assertStatus(200);

        // Moved to a brand with no restaurants → covers nothing → grants nothing.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/accountants/{$acc->id}/assignments", ['brands' => [$emptyBrand->id]])
            ->assertStatus(200);

        $this->assertSame([], $this->assignment($acc)->fresh()->module_keys);

        // Moving back restores the grid the admin built (cells are kept).
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/accountants/{$acc->id}/assignments", ['brands' => [$this->brand->id]])
            ->assertStatus(200);

        $this->assertEqualsCanonicalizing(
            ['sales', 'expenses', 'waste'],
            $this->assignment($acc)->fresh()->module_keys,
        );
    }

    public function test_module_key_outside_the_catalogue_is_rejected(): void
    {
        $acc = $this->accountant();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/accountants/{$acc->id}/restaurants/{$this->restaurantA->id}/modules", [
                'modules' => ['sales', 'not-a-module'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ---- the two list screens that showed 0 / uuids ----

    public function test_distribution_exposes_id_keyed_modules_and_named_restaurants(): void
    {
        $acc = $this->accountant();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/distribution/assign-modules', [
                'accountantId' => $acc->id, 'restaurantId' => $this->restaurantA->id, 'modules' => ['sales', 'waste'],
            ])->assertStatus(200);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/distribution');

        $res->assertStatus(200)
            // id-keyed (the name-keyed map cannot be indexed by a grid row)
            ->assertJsonPath("accModulesByRestaurant.{$acc->id}.{$this->restaurantA->id}", ['sales', 'waste'])
            // legacy name-keyed shape still there
            ->assertJsonPath("accModules.{$acc->id}.Bazooka Riyadh", ['sales', 'waste'])
            ->assertJsonPath('accountants.0.restaurantCount', 2)
            ->assertJsonPath('accountants.0.moduleCount', 3);

        $named = collect($res->json('allRestaurantsNamed'))->firstWhere('id', $this->restaurantA->id);
        $this->assertSame('Bazooka Riyadh', $named['name']);
        $this->assertSame('Bazooka', $named['brandName']);
        $this->assertCount(count(ModuleCatalog::keys()), $res->json('moduleCatalog'));
    }

    public function test_users_list_counts_brand_derived_restaurants_for_an_accountant(): void
    {
        $acc = $this->accountant();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=acc@matrix.test');

        $res->assertStatus(200)
            // was 0: restaurant_ids is empty by design for a brand-level accountant
            ->assertJsonPath('data.0.restaurantCount', 2)
            ->assertJsonPath('data.0.moduleCount', 2);
        $names = collect($res->json('data.0.restaurantsNamed'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Bazooka Riyadh', 'Bazooka Jeddah'], $names);
    }
}
