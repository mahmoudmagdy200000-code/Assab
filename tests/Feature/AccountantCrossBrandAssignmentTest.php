<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Reported 2026-08-11 — «ضفت علامة تجارية، عيّنت مطعمها للمحاسب، وملقاش لا العلامة
 * ولا المطعم».
 *
 * Admin "Add Brand" gives every brand a company of its own, so assigning an
 * accountant a restaurant of a NEW brand crosses a company boundary. The
 * assignment saved, the users screen kept showing the old brand, and every one
 * of the accountant's own screens filtered by the single `company_id` — so the
 * whole assignment was silently inert.
 */
class AccountantCrossBrandAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabUser $accountant;

    private AsabCompany $companyA;

    private AsabBrand $brandA;

    private AsabBrand $brandB;

    private AsabRestaurant $restaurantB;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AsabUser::create([
            'name' => 'أمين النظام', 'email' => 'admin@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        [$this->companyA, $this->brandA] = $this->brandWithOwnCompany('جورمية كافية');
        [, $this->brandB] = $this->brandWithOwnCompany('علامة المحمدى');

        $this->restaurantB = AsabRestaurant::create([
            'brand_id' => $this->brandB->id, 'company_id' => $this->brandB->company_id,
            'name' => 'مطعم 1', 'status' => 'active',
        ]);
        $this->branchB = Branch::factory()->create([
            'name' => 'مطعم 11',
            'asab_brand_id' => $this->brandB->id,
            'asab_restaurant_id' => $this->restaurantB->id,
            'asab_company_id' => $this->brandB->company_id,
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'نزار عبد القادر',
            'email' => 'nizar@asab.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id, 'role_key' => 'accountant',
            'scope' => 'brand', 'brand_ids' => [$this->brandA->id], 'restaurant_ids' => [],
        ]);
    }

    /** Mirrors admin "Add Brand": the brand arrives with a company of its own. */
    private function brandWithOwnCompany(string $name): array
    {
        $company = AsabCompany::create(['name' => $name, 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id, 'name' => $name, 'sub_status' => 'active', 'status' => 'active',
        ]);

        return [$company, $brand];
    }

    private function assignRestaurantB(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/distribution/assign-restaurant', [
                'accountantId' => $this->accountant->id,
                'restaurantId' => $this->restaurantB->id,
            ])
            ->assertStatus(204);
    }

    public function test_assigning_a_restaurant_puts_its_brand_on_the_users_screen(): void
    {
        $this->assignRestaurantB();

        $row = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?role=accountant')
            ->assertStatus(200)
            ->json('data'))->firstWhere('id', $this->accountant->id);

        $this->assertEqualsCanonicalizing(
            [$this->brandA->id, $this->brandB->id],
            $row['coveredBrands'],
        );
        $this->assertEqualsCanonicalizing(
            ['جورمية كافية', 'علامة المحمدى'],
            collect($row['brandsNamed'])->pluck('name')->all(),
        );
        $this->assertContains('مطعم 1', collect($row['restaurantsNamed'])->pluck('name')->all());
    }

    public function test_the_distribution_screen_shows_the_restaurants_brand(): void
    {
        $this->assignRestaurantB();

        $row = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/distribution')
            ->assertStatus(200)
            ->json('accountants'))->firstWhere('id', $this->accountant->id);

        $this->assertSame(2, $row['brandCount']);
        $this->assertEqualsCanonicalizing([$this->brandA->id, $this->brandB->id], $row['coveredBrands']);
    }

    public function test_the_accountant_sees_the_assigned_brand_in_their_own_portal(): void
    {
        $this->assignRestaurantB();

        $brands = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/accountant/inventory/brands')
            ->assertStatus(200)
            ->json();

        $this->assertEqualsCanonicalizing(
            [$this->brandA->id, $this->brandB->id],
            collect($brands)->pluck('id')->all(),
        );
    }

    public function test_the_accountant_can_open_the_assigned_restaurants_branch(): void
    {
        $this->assignRestaurantB();

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/branches/{$this->branchB->id}/daily-list")
            ->assertStatus(200);
    }

    /** The widening is assignment-driven: an untouched brand stays invisible. */
    public function test_an_unassigned_brand_of_another_company_stays_invisible(): void
    {
        $this->assignRestaurantB();
        [, $brandC] = $this->brandWithOwnCompany('علامة ثالثة');
        $branchC = Branch::factory()->create([
            'name' => 'فرع ثالث',
            'asab_brand_id' => $brandC->id,
            'asab_company_id' => $brandC->company_id,
        ]);

        $brands = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/accountant/inventory/brands')->assertStatus(200)->json();
        $this->assertNotContains($brandC->id, collect($brands)->pluck('id')->all());

        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/accountant/inventory/branches/{$branchC->id}/daily-list")
            ->assertStatus(404);
    }

    /** Un-assigning takes the coverage away again — nothing was written to brand_ids. */
    public function test_unassigning_the_restaurant_removes_its_brand(): void
    {
        $this->assignRestaurantB();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/admin/distribution/assign-restaurant', [
                'accountantId' => $this->accountant->id,
                'restaurantId' => $this->restaurantB->id,
            ])
            ->assertStatus(204);

        $brands = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/accountant/inventory/brands')->assertStatus(200)->json();

        $this->assertSame([$this->brandA->id], collect($brands)->pluck('id')->all());
    }
}
